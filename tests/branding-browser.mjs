const endpoint = process.argv[2] || 'http://127.0.0.1:9225';
const targetUrl = process.argv[3] || 'http://127.0.0.1:8098/login';
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const tabs = await (await fetch(`${endpoint}/json/list`)).json();
const target = tabs.find(tab => tab.type === 'page' && !tab.url.startsWith('chrome-extension://'));
if (!target?.webSocketDebuggerUrl) throw new Error('Chrome debugging tab is unavailable.');

const socket = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
let commandId = 0;
const pending = new Map();
socket.onmessage = event => {
  const message = JSON.parse(event.data);
  if (!message.id || !pending.has(message.id)) return;
  const handlers = pending.get(message.id);
  pending.delete(message.id);
  message.error ? handlers.reject(new Error(message.error.message)) : handlers.resolve(message.result);
};
const send = (method, params = {}) => new Promise((resolve, reject) => {
  const id = ++commandId;
  pending.set(id, {resolve, reject});
  socket.send(JSON.stringify({id, method, params}));
});
const evaluate = async expression => (await send('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true})).result.value;

await send('Page.enable');
await send('Runtime.enable');
await send('Page.navigate', {url: targetUrl});
await delay(1000);

const result = await evaluate(`(async()=>{
  const icon=document.querySelector('link[rel~="icon"]');
  const manifest=document.querySelector('link[rel="manifest"]');
  const iconResponse=icon?await fetch(icon.href):null;
  const manifestResponse=manifest?await fetch(manifest.href):null;
  return {
    title:document.title,
    loginFormPresent:Boolean(document.querySelector('form.auth-form')),
    developmentAccessPresent:document.body.innerText.includes('Development access'),
    developmentEmailPresent:document.body.innerText.includes('admin@rankersmind.local'),
    developmentPasswordPresent:document.body.innerText.includes('Admin@12345'),
    iconHref:icon?.href||'',
    iconType:icon?.type||'',
    iconStatus:iconResponse?.status||0,
    iconContentType:iconResponse?.headers.get('content-type')||'',
    manifestHref:manifest?.href||'',
    manifestStatus:manifestResponse?.status||0,
    themeColor:document.querySelector('meta[name="theme-color"]')?.content||''
  };
})()`);

if (!result.title.includes('Rankers Mind CRM') || !result.loginFormPresent || result.developmentAccessPresent || result.developmentEmailPresent || result.developmentPasswordPresent || !result.iconHref.endsWith('/assets/images/favicon.svg?v=1.0.0') || result.iconType !== 'image/svg+xml' || result.iconStatus !== 200 || !result.iconContentType.includes('image/svg+xml') || result.manifestStatus !== 200 || result.themeColor !== '#101936') {
  throw new Error(`Rankers Mind browser branding verification failed: ${JSON.stringify(result)}`);
}

console.log(JSON.stringify(result));
socket.close();
