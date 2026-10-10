const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const script = fs.readFileSync(path.join(__dirname, '../wizard-assets/setup.js'), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
const state = (job = {}) => ({authorized:true,configured:!!job.status,version:'3.8.31',deployment:{app_url:'https://office.example.test',admin_email:'admin@example.test',admin_name:'Test',bind_ip:'127.0.0.1',port:8080},job});
function page(replies) {
 const elements = new Map(), calls = [], timers = [], storage = new Map(); let loaded, hashCleared = false;
 const el = id => {
  if (!elements.has(id)) elements.set(id,{hidden:true,disabled:false,open:false,value:'',textContent:'',style:{},events:{},classList:{toggle(){}},setAttribute(){},addEventListener(name,fn){this.events[name]=fn;},showModal(){this.open=true;},close(){this.open=false;}});
  return elements.get(id);
 };
 vm.runInNewContext(script,{
  document:{getElementById:el,documentElement:{dataset:{}},addEventListener(name,fn){loaded=fn;}},
  location:{hash:'#'+'a'.repeat(64),pathname:'/'},history:{replaceState(){hashCleared=true;}},sessionStorage:{getItem:key=>storage.get(key),setItem:(key,val)=>storage.set(key,val)},localStorage:{getItem(){},setItem(){}},matchMedia:()=>({matches:false}),AbortController,
  setTimeout:(fn,ms)=>{timers.push({fn,ms});return timers.length;},clearTimeout:id=>{if(timers[id-1])timers[id-1].cancelled=true;},
  fetch:async(url,options)=>{calls.push({url,method:options.method,body:options.body}); assert.ok(replies.length,'Unexpected fetch');const next=replies.shift();return{ok:(next.status||200)<400,status:next.status||200,json:async()=>next.body,headers:{get:()=>null}};}
 }); loaded();
 return {el,calls,storage,get hashCleared(){return hashCleared;}, async event(id,event='click'){await el(id).events[event]({preventDefault(){}});await flush();},async nextPoll(){const timer=timers.find(t=>!t.cancelled&&t.ms===2500);assert.ok(timer);timer.cancelled=true;await timer.fn();await flush();}};
}
test('the installer opens confirmation without starting work, then keeps the window for real progress',async()=>{
 const ui=page([{body:state()},{status:202,body:state({id:'one',status:'running',stage:'build',progress:34})},{body:state({id:'one',status:'success',stage:'complete',progress:100})}]); await flush();
 assert.equal(ui.hashCleared,true);assert.equal(ui.storage.size,1);
 ui.el('password').value=ui.el('passwordConfirmation').value='test-only-password';
 await ui.event('settingsForm','submit');assert.equal(ui.el('installDialog').open,true);assert.equal(ui.calls.length,1);
 await ui.event('confirmInstall');assert.equal(ui.el('installDialog').open,true);assert.equal(ui.el('installPercent').textContent,'34%');assert.equal(ui.el('password').value,'');
 await ui.event('confirmInstall');assert.equal(ui.calls.length,2);
 await ui.nextPoll();assert.equal(ui.el('installPercent').textContent,'100%');assert.equal(ui.el('successDetails').hidden,false);assert.equal(ui.el('loginEmail').textContent,'admin@example.test');
 assert.ok([...ui.storage.values()].every(value=>!value.includes('password')));
});
test('a reload restores running progress and status reads never start installation',async()=>{
 const ui=page([{body:state({id:'saved',status:'running',stage:'services',progress:85})}]);await flush();
 assert.equal(ui.el('installDialog').open,true);assert.equal(ui.el('installPercent').textContent,'85%');assert.deepEqual(ui.calls.map(c=>c.method),['GET']);
 await ui.event('closeDialog');assert.equal(ui.el('installDialog').open,false);await ui.event('showProgress');assert.equal(ui.el('installDialog').open,true);
});
test('a lost start response discovers the job without replaying installation',async()=>{
 const ui=page([{body:state()},{status:502,body:{message:'connection lost'}},{body:state({id:'saved',status:'running',stage:'build',progress:47})}]);await flush();
 ui.el('password').value=ui.el('passwordConfirmation').value='test-only-password';await ui.event('settingsForm','submit');await ui.event('confirmInstall');
 assert.match(ui.el('installError').textContent,/connection lost/);await ui.nextPoll();assert.equal(ui.el('installPercent').textContent,'47%');assert.deepEqual(ui.calls.map(c=>c.method),['GET','POST','GET']);
});
test('a failed receipt never shows 100 and retries require fresh confirmation',async()=>{
 const ui=page([{body:state({id:'saved',status:'error',stage:'confirmation',progress:100,error:'receipt unavailable'})}]);await flush();
 assert.equal(ui.el('installPercent').textContent,'99%');assert.equal(ui.el('confirmInstall').hidden,false);assert.equal(ui.el('successDetails').hidden,true);assert.equal(ui.calls.length,1);
});
