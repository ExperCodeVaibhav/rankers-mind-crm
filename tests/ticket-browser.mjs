const endpoint = process.argv[2] || 'http://127.0.0.1:9223';
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
  const handlers = pending.get(message.id); pending.delete(message.id);
  message.error ? handlers.reject(new Error(message.error.message)) : handlers.resolve(message.result);
};
const send = (method, params = {}) => new Promise((resolve, reject) => {
  const id = ++commandId; pending.set(id, {resolve, reject}); socket.send(JSON.stringify({id, method, params}));
});
const evaluate = async expression => (await send('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true})).result.value;

await send('Page.enable');
await send('Runtime.enable');
await send('Network.enable');
await send('Network.clearBrowserCookies');
await send('Emulation.setDeviceMetricsOverride', {width: 390, height: 700, deviceScaleFactor: 1, mobile: true, screenWidth: 390, screenHeight: 700});
await send('Page.navigate', {url: 'http://localhost/rankers-mind-crm/public/login'});
await delay(1000);
await evaluate(`document.querySelector('[name=email]').value='client@rankersmind.local';document.querySelector('[name=password]').value='Client@12345';document.querySelector('form.auth-form').submit();true`);
await delay(1500);

const buttonPresent = await evaluate(`Boolean(document.querySelector('[data-modal-open="ticketModal"]'))`);
await evaluate(`document.querySelector('[data-modal-open="ticketModal"]').click();true`);
await delay(300);
const modalState = await evaluate(`(()=>{const dialog=document.getElementById('ticketModal');const body=dialog?.querySelector('.modal-body');return {open:dialog?.open||false,viewport:innerHeight,height:dialog?.getBoundingClientRect().height||0,bodyOverflow:body?getComputedStyle(body).overflowY:'',subjectFocused:document.activeElement?.name==='subject',closeVisible:Boolean(dialog?.querySelector('[data-modal-close]')?.getBoundingClientRect().height)}})()`);
if (!buttonPresent || !modalState.open || !modalState.closeVisible || modalState.height > modalState.viewport) {
  throw new Error('Support ticket modal is not usable: '+JSON.stringify({buttonPresent, modalState}));
}

await evaluate(`document.querySelector('#ticketModal [data-modal-close]').click();true`);
await delay(150);
const closed = await evaluate(`!document.getElementById('ticketModal').open && Boolean(document.querySelector('[data-modal-open="ticketModal"]'))`);
if (!closed) throw new Error('Support ticket modal did not release the application after closing.');

console.log(JSON.stringify({buttonPresent, modalState, closed}));
socket.close();
