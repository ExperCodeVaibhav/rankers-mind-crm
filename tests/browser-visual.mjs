import fs from 'node:fs';

const endpoint = process.argv[2] || 'http://127.0.0.1:9223';
const outputDir = process.argv[3] || 'storage';
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const tabs = await (await fetch(`${endpoint}/json/list`)).json();
const targetTab = tabs.find(tab => tab.type === 'page' && !tab.url.startsWith('chrome-extension://'));
if (!targetTab?.webSocketDebuggerUrl) throw new Error('Chrome debugging tab is unavailable.');

const socket = new WebSocket(targetTab.webSocketDebuggerUrl);
await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
let commandId = 0;
const pending = new Map();
socket.onmessage = event => {
  const message = JSON.parse(event.data);
  if (!message.id || !pending.has(message.id)) return;
  const { resolve, reject } = pending.get(message.id); pending.delete(message.id);
  message.error ? reject(new Error(message.error.message)) : resolve(message.result);
};
const send = (method, params = {}) => new Promise((resolve, reject) => {
  const id = ++commandId; pending.set(id, { resolve, reject }); socket.send(JSON.stringify({ id, method, params }));
});
const evaluate = async expression => (await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })).result.value;

await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
await send('Network.clearBrowserCookies');
await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true, screenWidth: 390, screenHeight: 844 });
await send('Page.navigate', { url: 'http://localhost/rankers-mind-crm/public/login' }); await delay(3300);
const loginMobileOverflow = await evaluate(`document.documentElement.scrollWidth > window.innerWidth`);
const loginMobileShot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
fs.writeFileSync(`${outputDir}/login-mobile.png`, Buffer.from(loginMobileShot.data, 'base64'));

await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1100, deviceScaleFactor: 1, mobile: false });
await send('Page.reload', { ignoreCache: true }); await delay(1200);
const loginDesktopOverflow = await evaluate(`document.documentElement.scrollWidth > window.innerWidth`);
const loginLogoOk = await evaluate(`Boolean(document.querySelector('.auth-logo')) && getComputedStyle(document.querySelector('.auth-logo')).backgroundImage.includes('rankers-mind-logo.png')`);
const loginDesktopShot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
fs.writeFileSync(`${outputDir}/login-desktop.png`, Buffer.from(loginDesktopShot.data, 'base64'));
await evaluate(`document.querySelector('[name=email]').value='admin@rankersmind.local';document.querySelector('[name=password]').value='Admin@12345';document.querySelector('form.auth-form').submit();true`);
await delay(1800);
const dashboardOk = await evaluate(`location.pathname.includes('/dashboard') && document.body.innerText.includes('Executive overview')`);
if (!dashboardOk) {
  const debugState = await evaluate(`({url:location.href,text:document.body.innerText.slice(0,500)})`);
  throw new Error('Authenticated dashboard did not render: ' + JSON.stringify(debugState));
}
const desktopOverflow = await evaluate(`document.documentElement.scrollWidth > window.innerWidth`);
const dashboardLogoOk = await evaluate(`Boolean(document.querySelector('.sidebar-logo')) && getComputedStyle(document.querySelector('.sidebar-logo')).backgroundImage.includes('rankers-mind-logo.png')`);
const desktopShot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
fs.writeFileSync(`${outputDir}/dashboard-desktop.png`, Buffer.from(desktopShot.data, 'base64'));

await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 760, deviceScaleFactor: 1, mobile: false });
await send('Page.reload', { ignoreCache: true }); await delay(900);
const sidebarScrollBefore = await evaluate(`(()=>{const nav=document.querySelector('.sidebar-nav');nav.scrollTop=nav.scrollHeight;nav.dispatchEvent(new Event('scroll'));return nav.scrollTop})()`);
await delay(120);
await evaluate(`document.querySelector('.sidebar-nav a[href$="/settings"]')?.click();true`); await delay(900);
const sidebarScrollAfter = await evaluate(`document.querySelector('.sidebar-nav')?.scrollTop || 0`);
const sidebarScrollPersistenceOk = sidebarScrollBefore > 0 && Math.abs(sidebarScrollAfter - sidebarScrollBefore) <= 2;
if (!sidebarScrollPersistenceOk) throw new Error('Sidebar scroll position was not retained across navigation: ' + JSON.stringify({sidebarScrollBefore, sidebarScrollAfter}));
await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1100, deviceScaleFactor: 1, mobile: false });
await send('Page.navigate', { url: 'http://localhost/rankers-mind-crm/public/dashboard' }); await delay(900);

const routeChecks = {};
for (const [name, path, marker] of [
  ['leads', '/leads', 'Bulk action'],
  ['estimates', '/estimates', 'Estimates & Quotations'],
  ['taskWorkflow', '/tasks/1', 'Checklist'],
  ['reports', '/reports', 'Reports Centre'],
  ['settings', '/settings', 'Office & geofence'],
  ['clientCredentials', '/clients/create', 'Separate client login'],
  ['manualTask', '/tasks/create', 'Create Manual Task'],
]) {
  await send('Page.navigate', { url: `http://localhost/rankers-mind-crm/public${path}` }); await delay(900);
  routeChecks[name] = await evaluate(`document.body.innerText.includes(${JSON.stringify(marker)}) && !document.body.innerText.includes('Fatal error') && document.documentElement.scrollWidth <= window.innerWidth`);
}
if (Object.values(routeChecks).some(result => !result)) throw new Error('A core browser route failed: ' + JSON.stringify(routeChecks));
await send('Page.navigate', { url: 'http://localhost/rankers-mind-crm/public/clients' }); await delay(900);
const clientsShot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
fs.writeFileSync(`${outputDir}/clients-desktop.png`, Buffer.from(clientsShot.data, 'base64'));
await send('Page.navigate', { url: 'http://localhost/rankers-mind-crm/public/clients/create' }); await delay(900);
await evaluate(`document.getElementById('clientPortalAccess')?.scrollIntoView({block:'start'});true`); await delay(300);
const clientFormShot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
fs.writeFileSync(`${outputDir}/client-form-desktop.png`, Buffer.from(clientFormShot.data, 'base64'));
await send('Page.navigate', { url: 'http://localhost/rankers-mind-crm/public/tasks' }); await delay(900);
const tasksShot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
fs.writeFileSync(`${outputDir}/tasks-desktop.png`, Buffer.from(tasksShot.data, 'base64'));
await send('Page.navigate', { url: 'http://localhost/rankers-mind-crm/public/settings' }); await delay(900);
const officeCoordinatesOk = await evaluate(`document.querySelector('[name=office_latitude]')?.value === '26.8101600' && document.querySelector('[name=office_longitude]')?.value === '75.8635100'`);
if (!loginLogoOk || !dashboardLogoOk || !officeCoordinatesOk) throw new Error('Branding or office-coordinate verification failed.');
await send('Page.navigate', { url: 'http://localhost/rankers-mind-crm/public/dashboard' }); await delay(900);

await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true, screenWidth: 390, screenHeight: 844 });
await send('Page.reload', { ignoreCache: true }); await delay(1500);
const mobileOverflow = await evaluate(`document.documentElement.scrollWidth > window.innerWidth`);
const mobileShot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
fs.writeFileSync(`${outputDir}/dashboard-mobile.png`, Buffer.from(mobileShot.data, 'base64'));

await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1100, deviceScaleFactor: 1, mobile: false });
await send('Network.clearBrowserCookies');
await send('Page.navigate', { url: 'http://localhost/rankers-mind-crm/public/login' }); await delay(900);
await evaluate(`document.querySelector('[name=email]').value='client@rankersmind.local';document.querySelector('[name=password]').value='Client@12345';document.querySelector('form.auth-form').submit();true`);
await delay(1800);
const clientPortalSignals = await evaluate(`({path:location.pathname,markers:Object.fromEntries(['SECURE CLIENT PORTAL','My projects','Invoices','Support','Documents','Company profile'].map(marker=>[marker,document.body.innerText.includes(marker)])),own:document.body.innerText.includes('Kapoor Retail Private Limited'),foreign:document.body.innerText.includes('Agarwal Foods')})`);
const clientPortalOk = clientPortalSignals.path.includes('/portal/client') && Object.values(clientPortalSignals.markers).every(Boolean) && clientPortalSignals.own && !clientPortalSignals.foreign;
const clientNavigationOk = await evaluate(`!document.querySelector('form.global-search') && Boolean(document.querySelector('.portal-context')) && Boolean(document.querySelector('a[href*="#payments"]')) && Boolean(document.querySelector('a[href*="#documents"]'))`);
const clientDesktopOverflow = await evaluate(`document.documentElement.scrollWidth > window.innerWidth`);
const clientDesktopShot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
fs.writeFileSync(`${outputDir}/client-portal-desktop.png`, Buffer.from(clientDesktopShot.data, 'base64'));
const firstInvoiceUrl = await evaluate(`document.querySelector('#invoices a[href*="/portal/invoices/"]')?.href || ''`);
if (!firstInvoiceUrl) throw new Error('Client invoice view link was not rendered.');
await send('Page.navigate', { url: firstInvoiceUrl }); await delay(900);
const clientInvoiceViewOk = await evaluate(`location.pathname.includes('/portal/invoices/') && document.body.innerText.includes('Invoice no.') && !document.body.innerText.includes('Fatal error')`);
await send('Page.navigate', { url: 'http://localhost/rankers-mind-crm/public/portal/client' }); await delay(900);
await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true, screenWidth: 390, screenHeight: 844 });
await send('Page.reload', { ignoreCache: true }); await delay(1000);
const clientMobileOverflow = await evaluate(`document.documentElement.scrollWidth > window.innerWidth`);
const clientMobileShot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
fs.writeFileSync(`${outputDir}/client-portal-mobile.png`, Buffer.from(clientMobileShot.data, 'base64'));
if (!clientPortalOk || !clientNavigationOk || !clientInvoiceViewOk || clientDesktopOverflow || clientMobileOverflow) throw new Error('Client portal browser verification failed: '+JSON.stringify({clientPortalOk,clientPortalSignals,clientNavigationOk,clientInvoiceViewOk,clientDesktopOverflow,clientMobileOverflow,clientUrl:await evaluate('location.href')}));

console.log(JSON.stringify({ dashboardOk, loginLogoOk, dashboardLogoOk, officeCoordinatesOk, sidebarScrollPersistenceOk, sidebarScrollBefore, sidebarScrollAfter, routeChecks, clientPortalOk, clientNavigationOk, clientInvoiceViewOk, loginDesktopOverflow, loginMobileOverflow, desktopOverflow, mobileOverflow, clientDesktopOverflow, clientMobileOverflow, url: await evaluate('location.href') }));
socket.close();
