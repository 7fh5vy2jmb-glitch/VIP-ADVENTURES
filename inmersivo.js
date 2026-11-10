/* Portada inmersiva. Lee los tours de app.js (servicios()), así los cambios del panel de administración se reflejan aquí. */
/* Espera a que app.js cargue los tours desde el servidor (listo). */
listo.then(function(){
const q=s=>document.querySelector(s),RM=matchMedia('(prefers-reduced-motion:reduce)').matches,clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
const S=servicios(),m0=v=>'$'+Number(v).toLocaleString('es-MX');
/* un color por tour, según el póster */
const COL={'Aquaventuras':['#1e8bff','rgba(30,139,255,.75)'],'Arcos, Madagascar y Ánimas':['#18b7c6','rgba(24,183,198,.75)'],'Arcos, Ánimas y Quimixto':['#ffb020','rgba(255,176,32,.75)'],'Yelapa y Majahuitas':['#14a37f','rgba(20,163,127,.75)'],'ATV y RZR':['#e5352f','rgba(229,53,47,.75)']};
const FALL=[['#18b7c6','rgba(24,183,198,.75)'],['#ff5abe','rgba(255,90,190,.75)'],['#ffb020','rgba(255,176,32,.75)']];
const corto=t=>{if(t.length<=170)return t;const c=t.slice(0,170),i=c.lastIndexOf(' ');return c.slice(0,i)+'…'};
const precio=s=>{const a=s.paquetes.filter(k=>k.precio>0);if(!a.length)return['Por cotizar','Precio al reservar'];const m=a.reduce((x,y)=>x.precio<=y.precio?x:y);return[m0(m.precio),'Por '+unit(s)+(m.grupo>0?' · '+m0(m.grupo)+' desde 5':'')]};
const N=S.length,cars=q('#cars'),bgs=q('#bgs'),dots=q('#dots'),els=[],bgEls=[];let cur=-1,mx=0,my=0;
S.forEach((s,i)=>{
 const c=COL[s.nombre]||FALL[i%FALL.length];
 const a=document.createElement('div');a.className='poster';a.innerHTML=`<img src="${esc(s.img)}" alt="Póster del tour ${esc(s.nombre)}"${i?' loading="lazy"':''}>`;cars.appendChild(a);els.push(a);
 const b=document.createElement('div');b.style.setProperty('--k',c[0]);bgs.appendChild(b);bgEls.push(b);
 const d=document.createElement('button');d.type='button';d.setAttribute('aria-label',s.nombre);d.onclick=()=>goTo(i);dots.appendChild(d);
});
const show=q('#tours'),pin=q('#pin');
const goTo=i=>{const top=show.getBoundingClientRect().top+scrollY,range=show.offsetHeight-innerHeight;scrollTo({top:top+range*(i/(N-1))+1,behavior:RM?'auto':'smooth'})};
const setText=i=>{
 if(i===cur)return;cur=i;const s=S[i],a=q('#txt'),m=q('#mt');a.classList.add('out');m.classList.add('out');
 setTimeout(()=>{
  q('#tt').textContent=s.nombre;q('#td').textContent=corto(s.desc);
  const p=precio(s);q('#tp').innerHTML=esc(p[0])+'<small>'+esc(p[1])+'</small>';
  q('#count').textContent=String(i+1).padStart(2,'0')+' / '+String(N).padStart(2,'0');
  q('.floor').style.setProperty('--glow',(COL[s.nombre]||FALL[i%FALL.length])[1]);
  [...dots.children].forEach((x,j)=>x.setAttribute('aria-current',j===i));
  q('#tb').href='comprar.html?tour='+i;
  a.classList.remove('out');m.classList.remove('out');
 },RM?0:220);
};
const frame=()=>{
 const r=show.getBoundingClientRect(),range=show.offsetHeight-innerHeight,p=clamp(-r.top/range,0,1),f=p*(N-1),sp=clamp(innerWidth*.46,150,620);
 q('.bar').style.setProperty('--p',p.toFixed(4));
 for(let i=0;i<N;i++){
  const d=i-f,ad=Math.abs(d),near=1-Math.min(ad,1);
  els[i].style.transform=`translateX(${(d*sp).toFixed(1)}px) translateZ(${(-ad*260).toFixed(1)}px) rotateY(${(clamp(-d,-1.6,1.6)*38+mx*6*near).toFixed(2)}deg) rotateX(${(my*-5*near).toFixed(2)}deg) scale(${(1-Math.min(ad,2)*.08).toFixed(3)})`;
  els[i].style.opacity=clamp(1.15-ad*.55,0,1).toFixed(3);els[i].style.zIndex=String(10-Math.round(ad*2));
  bgEls[i].style.opacity=clamp(1-ad*1.1,0,1).toFixed(3);
 }
 setText(clamp(Math.round(f),0,N-1));
 const ir=q('#ink').getBoundingClientRect(),ip=clamp(1-(ir.top+ir.height*.2)/(innerHeight+ir.height*.2),0,1),rs=[.9,.7,1,.6,.5],st=[0,.1,.2,.3,.05],rad=[170,190,200,130,120];
 for(let j=0;j<5;j++){const t=clamp((ip-st[j])/(1-st[j]),0,1);q('#b'+(j+1)).setAttribute('r',(10+rad[j]*Math.pow(t,.8)*rs[j]).toFixed(1))}
 const big=q('#big');big.style.setProperty('--bgy',(20+ip*60).toFixed(1)+'%');big.style.setProperty('--bgx',(ip*60).toFixed(1)+'%');
};
let tick=false;const req=()=>{if(!tick){tick=true;requestAnimationFrame(()=>{tick=false;frame()})}};
addEventListener('scroll',req,{passive:true});addEventListener('resize',req);
if(!RM)show.addEventListener('pointermove',e=>{mx=e.clientX/innerWidth*2-1;my=e.clientY/innerHeight*2-1;req()});
frame();
/* logo que sigue el cursor */
const orb=q('#orb'),hero=q('.hv');
if(!RM){hero.addEventListener('pointermove',e=>{const r=hero.getBoundingClientRect(),x=(e.clientX-r.left)/r.width*2-1,y=(e.clientY-r.top)/r.height*2-1;orb.style.transform=`rotateY(${x*24}deg) rotateX(${-y*18}deg)`});hero.addEventListener('pointerleave',()=>{orb.style.transform=''})}
/* globo de mensajes */
const M=['¿Eres visitante? <b>Tu día en la bahía</b> empieza aquí.','¿Snorkel, kayak o paddle? <b>Tú eliges</b>.','¿Prefieres tierra? Hay <b>ATV y RZR</b> con guías expertos.','¿Vienen 5 o más? <b>Pagan menos</b> por persona.'],bt=q('#bt');let mi=0;
bt.style.transition='opacity .25s';
if(!RM)setInterval(()=>{mi=(mi+1)%M.length;bt.style.opacity=0;setTimeout(()=>{bt.innerHTML=M[mi];bt.style.opacity=1},250)},4200);
/* cinta */
const w=['Snorkel','Kayak','Paddleboard','Barra libre','Comida a bordo','Guías expertos','Equipo de seguridad','Salidas diarias','Lancha','Playas cristalinas'];
let h='';w.forEach(x=>h+='<span>'+x+'</span>');q('#mq').innerHTML=h+h+h+h;
/* precio de grupo (usa los precios del panel) */
const G=S.flatMap(s=>s.paquetes).filter(k=>k.grupo>0),base=G.length?Math.max(...G.map(k=>k.precio)):500,gp=G.length?Math.min(...G.map(k=>k.grupo)):400;
q('#glead').textContent=`En los tours seleccionados el precio por persona baja de ${m0(base)} a ${m0(gp)} cuando van 5 o más. Mueve la barra y míralo.`;
q('#gnote').textContent='Precios por persona en pesos mexicanos. Los tours sin precio publicado se cotizan al reservar.';
const n=q('#n'),pe=q('#people');for(let k=0;k<10;k++)pe.appendChild(document.createElement('i'));
const calc=()=>{const v=+n.value,g=v>=5;q('#nv').textContent=v;[...pe.children].forEach((x,i)=>x.className=i<v?'on':'');pe.className='people'+(g?' g':'');
 const p=g?gp:base;q('#pp').innerHTML=(g?'<s>'+m0(base)+'</s>':'')+m0(p);q('#tot').textContent='Total '+m0(p*v);
 const b=q('#bd');b.className='badge'+(g?' on':'');b.textContent=g?'Precio de grupo aplicado':`Con 5 personas pagas ${m0(gp)} cada uno`};
n.addEventListener('input',calc);calc();
/* copiar contacto */
document.querySelectorAll('.copy').forEach(b=>b.addEventListener('click',()=>{const el=document.getElementById(b.dataset.c),t=el.textContent.trim();
 const ok=()=>{b.textContent='Copiado';setTimeout(()=>b.textContent='Copiar',1600)},fb=()=>{const r=document.createRange();r.selectNodeContents(el);const s=getSelection();s.removeAllRanges();s.addRange(r);b.textContent='Seleccionado'};
 try{navigator.clipboard.writeText(t).then(ok,fb)}catch(e){fb()}}));
});
