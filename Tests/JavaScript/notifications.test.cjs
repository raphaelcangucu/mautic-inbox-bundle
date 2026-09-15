const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict');
const {parseHTML}=require('linkedom');
(async()=>{
 const {document,window}=parseHTML('<html><head><link rel="icon" href="/favicon.ico"></head><body><div id="inbox-app" data-current-user="1"><button id="inbox-sound"></button></div></body></html>');
 let starts=0;const values=new Map();
 class Audio {constructor(){this.state='suspended';this.currentTime=0;}resume(){this.state='running';return Promise.resolve();}close(){return Promise.resolve();}createOscillator(){return {frequency:{},connect(){},start(){starts++},stop(){}};}createGain(){return {gain:{setValueAtTime(){},linearRampToValueAtTime(){},exponentialRampToValueAtTime(){}},connect(){}};}}
 window.AudioContext=Audio;
 const create=document.createElement.bind(document);
 document.createElement=(tag)=>{const e=create(tag);if(tag==='canvas'){e.getContext=()=>({fillRect(){},drawImage(){},beginPath(){},arc(){},fill(){},fillText(){}});e.toDataURL=()=> 'data:image/png;base64,test';}return e;};
 const source=fs.readFileSync(require('path').join(__dirname,'../../Assets/js/inbox.js'),'utf8');
 const code=source.slice(source.indexOf('    function createInboxAlerts('),source.indexOf('    function boot()'));
 const labels={
  'mautic.inbox.ui.enable_sound_e0b13b':'Ativar som',
  'mautic.inbox.ui.sound_on_ff7013':'Som ligado',
  'mautic.inbox.ui.sound_off_95de2d':'Som desligado',
  'mautic.inbox.ui.sound_unavailable_029b22':'Som indisponível',
  'mautic.inbox.ui.sound_notifications_for_new_messages_click_to_enable_or_mute_5f2dc2':'Avisos sonoros',
  'mautic.inbox.ui.enable_sound_notifications_9d9271':'Ativar avisos sonoros'
 };
 const context={window,document,Image:class{},localStorage:{getItem:k=>values.get(k)||null,setItem:(k,v)=>values.set(k,v)},navigator:{},Map,Array,Math,t:key=>labels[key]||key};
 vm.createContext(context);vm.runInContext(code+';globalThis.factory=createInboxAlerts;',context);
 const root=document.getElementById('inbox-app'),button=document.getElementById('inbox-sound'),alerts=context.factory(root);
 assert.equal(starts,0);assert.equal(button.textContent,'Ativar som');
 button.click();await Promise.resolve();assert.equal(button.textContent,'Som ligado');
 alerts.receive([{id:10,state_id:2}]);assert.equal(starts,2);assert.match(document.querySelector('link').href,/data:image/);
 alerts.receive([{id:10,state_id:2}]);assert.equal(starts,2);
 alerts.acknowledge(3);assert.match(document.querySelector('link').href,/data:image/);
 alerts.acknowledge(2);assert.equal(document.querySelector('link').getAttribute('href'),'/favicon.ico');
 button.click();alerts.receive([{id:11,state_id:3}]);assert.equal(starts,2);assert.equal(button.textContent,'Som desligado');
 alerts.dispose();assert.equal(document.querySelectorAll('link').length,1);assert.equal(document.querySelector('link').getAttribute('href'),'/favicon.ico');
 console.log('Notifications: baseline, gesture, sound, deduplication, mute, favicon and cleanup passed');
})();
