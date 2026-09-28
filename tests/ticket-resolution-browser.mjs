const endpoint = process.argv[2] || 'http://127.0.0.1:9224';
const ticketId = Number(process.argv[3]);
if (!ticketId) throw new Error('Pass the temporary ticket ID as the second argument.');

const base = 'http://localhost/rankers-mind-crm/public';
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
const evaluate = async expression => {
  const response = await send('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true});
  if (response.exceptionDetails) throw new Error(response.exceptionDetails.text || 'Browser evaluation failed.');
  return response.result.value;
};
const navigate = async url => {
  await send('Page.navigate', {url});
  await delay(900);
};
const login = async (email, password) => {
  await send('Network.clearBrowserCookies');
  await navigate(`${base}/login`);
  const submitted = await evaluate(`(()=>{const form=document.querySelector('form.auth-form');if(!form)return false;form.querySelector('[name=email]').value=${JSON.stringify(email)};form.querySelector('[name=password]').value=${JSON.stringify(password)};form.submit();return true})()`);
  if (!submitted) throw new Error(`Login form was not available for ${email}.`);
  await delay(1100);
};

await send('Page.enable');
await send('Runtime.enable');
await send('Network.enable');

await login('staff@rankersmind.local', 'Staff@12345');
await navigate(`${base}/tickets/${ticketId}`);
const staffAction = await evaluate(`(()=>{const panel=document.querySelector('[data-ticket-workflow-state]');const form=document.querySelector('.ticket-resolve-form');return {state:panel?.dataset.ticketWorkflowState||'',button:form?.querySelector('button')?.textContent.trim()||'',hasNote:Boolean(form?.querySelector('[name=resolution_note]'))}})()`);
if (staffAction.state !== 'open' || !staffAction.hasNote || !staffAction.button.includes('Mark resolved')) {
  throw new Error(`Concerned staff resolve control is missing: ${JSON.stringify(staffAction)}`);
}
const resolvedSubmitted = await evaluate(`(()=>{const form=document.querySelector('.ticket-resolve-form');if(!form)return false;form.querySelector('[name=resolution_note]').value='Browser verified resolution from concerned project staff.';form.submit();return true})()`);
if (!resolvedSubmitted) throw new Error('Concerned staff could not submit the resolution form.');
await delay(1100);
const awaitingClient = await evaluate(`(()=>({state:document.querySelector('[data-ticket-workflow-state]')?.dataset.ticketWorkflowState||'',text:document.querySelector('.ticket-resolution-panel')?.innerText||''}))()`);
if (awaitingClient.state !== 'resolved' || !awaitingClient.text.includes('Awaiting client confirmation')) {
  throw new Error(`Staff resolution did not enter client confirmation: ${JSON.stringify(awaitingClient)}`);
}

await login('client@rankersmind.local', 'Client@12345');
await navigate(`${base}/portal/tickets/${ticketId}`);
const clientPrompt = await evaluate(`(()=>{const section=document.querySelector('.client-resolution-confirmation');return {question:section?.querySelector('h2')?.textContent.trim()||'',buttons:[...document.querySelectorAll('.confirmation-actions button')].map(button=>button.textContent.trim())}})()`);
if (clientPrompt.question !== 'Is your issue resolved?' || !clientPrompt.buttons.some(text => text.includes('Yes, issue resolved')) || !clientPrompt.buttons.some(text => text.includes('No, issue continues'))) {
  throw new Error(`Client confirmation controls are missing: ${JSON.stringify(clientPrompt)}`);
}
const yesSubmitted = await evaluate(`(()=>{const form=[...document.querySelectorAll('.confirmation-actions form')].find(item=>item.querySelector('[name=resolved]')?.value==='yes');if(!form)return false;form.submit();return true})()`);
if (!yesSubmitted) throw new Error('Client Yes confirmation could not be submitted.');
await delay(1100);
const clientClosed = await evaluate(`(()=>({stateText:document.querySelector('.client-closed-state')?.innerText||'',badge:document.querySelector('.page-header .status-badge')?.textContent.trim()||''}))()`);
if (clientClosed.badge !== 'Closed' || !clientClosed.stateText.includes('You confirmed that this issue was resolved')) {
  throw new Error(`Ticket was not closed by client confirmation: ${JSON.stringify(clientClosed)}`);
}

console.log(JSON.stringify({staffAction, awaitingClient: awaitingClient.state, clientPrompt, clientClosed}));
socket.close();
