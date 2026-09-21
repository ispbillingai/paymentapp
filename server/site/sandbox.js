'use strict';
(() => {
  const el=id=>document.getElementById(id);let selected=null;let pendingIntent=null;
  function message(text){el('sandbox-message').textContent=text;el('sandbox-message').hidden=false;}
  async function request(path,method='GET',data,headers={}){
    const response=await fetch(path,{method,credentials:'same-origin',headers:{Accept:'application/json',...(method==='GET'?{}:{'Content-Type':'application/json','X-Portal-Request':'1'}),...headers},...(data===undefined?{}:{body:JSON.stringify(data)})});
    const json=await response.json();if(!response.ok){const error=new Error(json.error?.message||'Request failed.');error.status=response.status;throw error;}return json;
  }
  function secret(key){el('sandbox-secret-value').textContent=key;el('sandbox-secret').hidden=false;el('sandbox-secret').scrollIntoView({block:'center'});}
  function select(intent){selected=intent;el('sandbox-selected').textContent=intent.id+' · '+intent.status;el('sandbox-scenario').hidden=false;document.querySelectorAll('[data-scenario]').forEach(b=>b.disabled=b.dataset.scenario==='reversal'?intent.status!=='succeeded':intent.status!=='waiting');}
  async function refresh(){
    try{
      const data=await request('/v1/portal/sandbox');el('sandbox-join').hidden=true;el('sandbox-workspace').hidden=false;
      el('sandbox-account').textContent=data.account||'Create your first sandbox';el('enable-sandbox').hidden=!!data.account;
      el('sandbox-intent').hidden=!data.account;el('sandbox-rotate').hidden=!data.account;
      el('sandbox-key-list').replaceChildren();data.keys.forEach(k=>{const p=document.createElement('p');p.className='key-hint';p.textContent=k.hint+'… · '+k.status;el('sandbox-key-list').append(p);});
      el('sandbox-history').replaceChildren();
      if(!data.intents.length){const tr=document.createElement('tr');const td=document.createElement('td');td.colSpan=4;td.textContent='No test payments yet. Create your first intent above.';tr.append(td);el('sandbox-history').append(tr);}
      data.intents.forEach(i=>{const tr=document.createElement('tr');[i.reference,i.currency+' '+i.amount,i.status].forEach(t=>{const td=document.createElement('td');td.textContent=t;tr.append(td);});const td=document.createElement('td');const b=document.createElement('button');b.className='copy-button';b.textContent='Inspect';b.onclick=()=>{select(i);el('sandbox-scenario').scrollIntoView({block:'center'});};td.append(b);tr.append(td);el('sandbox-history').append(tr);});
      el('sandbox-events').replaceChildren();if(!data.events.length)el('sandbox-events').textContent='No events yet. Simulate a scenario to create one.';
      data.events.forEach(event=>{const details=document.createElement('details');const summary=document.createElement('summary');summary.textContent=event.type+' · '+event.created_at;const pre=document.createElement('pre');pre.textContent=JSON.stringify(JSON.parse(event.payload),null,2);const b=document.createElement('button');b.className='copy-button';b.textContent='Copy event';b.onclick=async()=>{try{await navigator.clipboard.writeText(pre.textContent);b.textContent='Copied';}catch(_){message('Select the event text and copy it manually.');}};details.append(summary,b,pre);el('sandbox-events').append(details);});
    }catch(error){if(error.status===401){el('sandbox-join').hidden=false;el('sandbox-workspace').hidden=true;}else message(error.message);}
  }
  function action(form,fn){form.addEventListener('submit',async e=>{e.preventDefault();const b=form.querySelector('button[type="submit"]');b.disabled=true;try{await fn();}catch(error){message(error.message);}finally{b.disabled=false;}});}
  action(el('sandbox-register'),async()=>{const data=await request('/v1/sandbox/register','POST',{email:el('sb-email').value.trim(),password:el('sb-password').value});el('sandbox-register').reset();el('sandbox-join').hidden=true;secret(data.api_key);message('Developer account created. Save your test key, then sign in.');});
  action(el('sandbox-intent'),async()=>{const payload={amount:el('sb-amount').value,currency:el('sb-currency').value.toUpperCase(),reference:el('sb-reference').value};const signature=JSON.stringify(payload);if(!pendingIntent||pendingIntent.signature!==signature)pendingIntent={signature,key:crypto.randomUUID()};const data=await request('/v1/portal/sandbox/intents','POST',payload,{'Idempotency-Key':pendingIntent.key});pendingIntent=null;await refresh();select(data.intent);});
  action(el('sandbox-rotate'),async()=>{const data=await request('/v1/portal/sandbox/keys','POST',{password:el('sb-confirm').value});el('sb-confirm').value='';await refresh();secret(data.api_key);});
  document.querySelectorAll('[data-scenario]').forEach(b=>b.addEventListener('click',async()=>{if(!selected)return;b.disabled=true;try{const data=await request('/v1/portal/sandbox/intents/'+selected.id+'/simulate','POST',{scenario:b.dataset.scenario});await refresh();select(data.intent);}catch(error){message(error.message);select(selected);}}));
  el('enable-sandbox').onclick=async()=>{el('enable-sandbox').disabled=true;try{const data=await request('/v1/portal/sandbox','POST',{});await refresh();secret(data.api_key);}catch(error){message(error.message);}finally{el('enable-sandbox').disabled=false;}};
  el('sandbox-refresh').onclick=refresh;
  el('sandbox-copy').onclick=async()=>{try{await navigator.clipboard.writeText(el('sandbox-secret-value').textContent);message('Test key copied. Store it securely.');}catch(_){message('Select the key and copy it manually.');}};
  refresh();
})();
