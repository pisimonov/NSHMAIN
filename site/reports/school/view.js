"use strict";
/* Отчёт «Экономика школы»: воронка продаж школы, сравнения, план и потери. Данные - window.SCHOOL_DATA (report.php). */
const DATA=window.SCHOOL_DATA, META=DATA.meta;
const ymOf=s=>({y:+s.slice(0,4),m:+s.slice(5,7)-1});
const CUR=Object.assign(ymOf(META.cur),{n:META.curN}), PREV=Object.assign(ymOf(META.prev),{n:META.prevN});
const D=META.D||1;
const SCHOOLS=(DATA.schools||[]).map(x=>Object.assign(x,{name:x.city}));
const REFS=DATA.refs||{};
const nk=s=>String(s||'').toLowerCase().replace(/ё/g,'е').replace(/\s+/g,' ').trim();
const refFor=dir=>REFS[nk(dir)]||REFS['*']||null;

/* ---------- форматирование ---------- */
const nf0=new Intl.NumberFormat('ru-RU',{maximumFractionDigits:0});
const ok=v=>v!=null&&isFinite(v);
const z=v=>ok(v)?v:0;
const rub=v=>ok(v)?nf0.format(Math.round(v))+' ₽':'н/д';
const num=v=>ok(v)?nf0.format(Math.round(v)):'н/д';
const mln=v=>!ok(v)?'н/д':(Math.abs(v)>=1e6?(v/1e6).toFixed(2).replace('.',',')+' млн ₽':nf0.format(Math.round(v/1000))+' тыс ₽');
const pc=(v,d=1)=>ok(v)?(v*100).toFixed(d).replace('.',',')+'%':'н/д';
const sgn=(v,f)=>(v>0?'+':v<0?'−':'')+f(Math.abs(v));
const pp=v=>ok(v)?sgn(v*100,x=>x.toFixed(1).replace('.',','))+' п.п.':'';
const RU_MG=['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
const RU_M=['январь','февраль','март','апрель','май','июнь','июль','август','сентябрь','октябрь','ноябрь','декабрь'];

/* ---------- расчёт ---------- */
const sum=(a,n)=>{let t=0;for(let i=0;i<Math.min(n,a.length);i++)t+=a[i];return t;};
function agg(m,n){const L=sum(m.L,n),B=sum(m.B,n),M=sum(m.M,n),S=sum(m.S,n),NR=sum(m.NR,n),OR=sum(m.OR,n);
  return {L,B,M,S,NR,OR,rev:NR+OR,cr1:L?B/L:null,cr2:B?M/B:null,cr3:M?S/M:null,crT:L?S/L:null,chk:S?NR/S:null,rpl:L?NR/L:null};}
/** Среднее по другим школам направления с данными: объёмы - на одну школу, конверсии - по суммам (взвешенно). */
function dirAvg(s,n){
  const peers=SCHOOLS.filter(x=>x.dir===s.dir&&x.id!==s.id).map(x=>agg(x.cur,n)).filter(a=>a.L>0||a.rev>0);
  if(!peers.length)return null;
  const t={L:0,B:0,M:0,S:0,NR:0,OR:0};peers.forEach(a=>{for(const k in t)t[k]+=a[k];});const k=peers.length;
  return Object.assign(agg({L:[t.L/k],B:[t.B/k],M:[t.M/k],S:[t.S/k],NR:[t.NR/k],OR:[t.OR/k]},1),{peers:k});}
function bases(s){
  const pv=agg(s.prev,Math.min(D,PREV.n)), dv=dirAvg(s,D), ref=refFor(s.dir);
  return {
    prev:pv.L>0||pv.rev>0?Object.assign(pv,{label:'Прошлый месяц',short:'прош. мес.',note:'1-'+Math.min(D,PREV.n)+' '+RU_MG[PREV.m]}):null,
    dir:dv?Object.assign(dv,{label:'Направление',short:'направл.',note:'среднее по '+dv.peers+' школам «'+s.dir+'», 1-'+D+' '+RU_MG[CUR.m]}):null,
    ref:ref?{cr1:ref.cr1,cr2:ref.cr2,cr3:ref.cr3,crT:ref.cr1*ref.cr2*ref.cr3,chk:ref.chk,rpl:ref.cr1*ref.cr2*ref.cr3*ref.chk,label:'Эталон',short:'эталон',note:(REFS[nk(s.dir)]?'направление «'+s.dir+'»':'общий для сети'),noAbs:true}:null,
  };
}
const usable=b=>b&&ok(b.cr1)&&ok(b.cr2)&&ok(b.cr3)&&ok(b.chk);
/** План на сегодня (равномерно по дням) и разложение отклонения выручки по факторам (цепные подстановки). */
function planGap(s,f,t){
  if(!s.plan||!usable(t))return null;
  const kD=D/CUR.n, Lp=s.plan.leads*kD, Rp=s.plan.rev*kD;
  const a=z(f.cr1),b=z(f.cr2),c=z(f.cr3),k=z(f.chk);
  const newT=Lp*t.cr1*t.cr2*t.cr3*t.chk, oldT=Math.max(0,Rp-newT);
  const f1=Lp*t.cr1*t.cr2*t.cr3*(k-t.chk);      // средний чек
  const f2=Lp*t.cr1*t.cr2*(c-t.cr3)*k;          // встреча -> продажа
  const f3=Lp*t.cr1*(b-t.cr2)*c*k;              // постановка -> встреча
  const f4=Lp*(a-t.cr1)*b*c*k;                  // лид -> постановка
  const f5=(f.L-Lp)*a*b*c*k;                    // лиды (при текущих конверсиях и чеке)
  const f6=f.OR-oldT;                           // продажи текущим ученикам
  // если план выручки меньше новых продаж по цели, старые продажи «по плану» = 0, и сумма факторов отличается от отклонения на эту разницу
  return {Lp,Rp,newT,oldT,factors:[
    {k:'L',  name:'Лиды',                    v:f5,how:'(лиды факт − лиды план) × текущие конверсии × текущий чек'},
    {k:'cr1',name:'Лид → постановка',        v:f4,how:'лиды план × (CR факт − CR цель) × текущие следующие конверсии × чек'},
    {k:'cr2',name:'Постановка → встреча',    v:f3,how:'лиды план × CR1 цель × (CR факт − CR цель) × текущие CR3 и чек'},
    {k:'cr3',name:'Встреча → продажа',       v:f2,how:'лиды план × CR1, CR2 цель × (CR факт − CR цель) × текущий чек'},
    {k:'chk',name:'Средний чек',             v:f1,how:'плановые первые продажи × (чек факт − чек цель)'},
    {k:'old',name:'Продажи текущим ученикам',v:f6,how:'старые продажи факт − (план выручки − новые продажи по цели)'}]};
}

/* ---------- DOM и тултип ---------- */
function h(tag,attrs,...kids){const e=document.createElement(tag);if(attrs)for(const k in attrs){const v=attrs[k];if(v==null||v===false)continue;if(k==='class')e.className=v;else if(k==='text')e.textContent=v;else if(k==='style')e.style.cssText=v;else if(k.startsWith('on'))e.addEventListener(k.slice(2),v);else e.setAttribute(k,v);}for(const c of kids.flat()){if(c==null||c===false)continue;e.appendChild(typeof c==='string'||typeof c==='number'?document.createTextNode(String(c)):c);}return e;}
const SVGNS='http://www.w3.org/2000/svg';
function sv(tag,attrs,parent){const e=document.createElementNS(SVGNS,tag);for(const k in attrs)e.setAttribute(k,attrs[k]);if(parent)parent.appendChild(e);return e;}
function txt(p,x,y,str,a){const t=sv('text',Object.assign({x,y},a||{}),p);t.textContent=str;return t;}
const tip=document.getElementById('tip');
function showTip(ev,title,rows){tip.textContent='';tip.appendChild(h('div',{class:'t',text:title}));
  for(const r of rows){tip.appendChild(h('div',{class:'r'},h('span',{class:'k'},r.color?h('span',{class:r.rect?'key-rect':'key-line',style:(r.rect?'background:':'border-color:')+r.color}):null,r.label),h('b',{text:r.value})));}
  tip.style.display='block';const w=tip.offsetWidth,hh=tip.offsetHeight;let x=ev.clientX+14,y=ev.clientY+14;if(x+w>innerWidth-8)x=ev.clientX-w-14;if(y+hh>innerHeight-8)y=ev.clientY-hh-14;tip.style.left=x+'px';tip.style.top=y+'px';}
const hideTip=()=>tip.style.display='none';
const hover=(el,fn)=>{el.addEventListener('pointermove',ev=>{const t=fn();showTip(ev,t.title,t.rows);});el.addEventListener('pointerleave',hideTip);return el;};
function chip(delta,goodIfUp,fmt){if(!ok(delta))return null;if(Math.abs(delta)<1e-9)return h('span',{class:'chip small',text:'='});const good=(delta>0)===goodIfUp;
  return h('span',{class:'chip '+(good?'up':'down')},h('span',{class:'ic',text:delta>0?'▲':'▼'}),fmt(delta));}
const tile=(lbl,val,cls,det)=>h('div',{class:'tile'},h('div',{class:'lbl',text:lbl}),h('div',{class:'val'+(cls?' '+cls:''),text:val}),h('div',{class:'det'},...det.map(d=>h('div',{},d))));
const niceStep=top=>{const st=Math.pow(10,Math.floor(Math.log10(Math.max(top,1)/4)));return [1,2,2.5,5,10].map(x=>x*st).find(x=>top/x<=5);};
const axisTxt=v=>v>=1e6?(v/1e6).toFixed(1).replace('.',',')+' млн':v>=1e3?nf0.format(v/1e3)+' тыс':nf0.format(v);

/* ---------- состояние ---------- */
const store={get(k,d){try{const v=localStorage.getItem('ns_school_'+k);return v==null?d:JSON.parse(v);}catch(e){return d;}},set(k,v){try{localStorage.setItem('ns_school_'+k,JSON.stringify(v));}catch(e){}}};
const state={school:store.get('school',null),cmp:store.get('cmp','prev'),target:store.get('target','ref')};
if(!SCHOOLS.some(x=>x.id===state.school))state.school=SCHOOLS.length?SCHOOLS[0].id:null;

/* ---------- 1. План и факт ---------- */
function renderPlan(main,s,f,gap){
  const kD=D/CUR.n, fcR=f.rev/kD, fcL=f.L/kD;
  main.appendChild(h('h2',{text:'План и факт на '+D+' '+RU_MG[CUR.m]+' · накопительно'}));
  if(!s.plan){
    main.appendChild(h('div',{class:'info-banner',text:'Плана на '+RU_M[CUR.m]+' для этой школы нет: добавьте строку на лист «'+META.sheets.plan+'» (город и направление - как в воронке). Ниже - факт и прогноз без сравнения с планом.'}));
    main.appendChild(h('div',{class:'grid-tiles four'},tile('Выручка: факт 1-'+D,mln(f.rev),'',['новые '+mln(f.NR)+' · старые '+mln(f.OR)]),tile('Лиды: факт 1-'+D,num(f.L),'',['']),
      tile('Прогноз выручки на '+RU_M[CUR.m],mln(fcR),'',['по темпу 1-'+D+' числа']),tile('Прогноз лидов',num(fcL),'',['по темпу 1-'+D+' числа'])));
  } else {
    const Lp=s.plan.leads*kD, Rp=s.plan.rev*kD, dR=f.rev-Rp, dL=f.L-Lp;
    main.appendChild(h('p',{class:'sub',text:'План на сегодня = план месяца × '+D+'/'+CUR.n+' дней. Прогноз месяца - по темпу 1-'+D+' числа.'}));
    main.appendChild(h('div',{class:'grid-tiles four'},
      tile('Выручка: факт 1-'+D,mln(f.rev),'',['план на сегодня '+mln(Rp),h('span',{},chip(dR,true,v=>mln(v)),' ',pc(Rp?dR/Rp:null,1))]),
      tile('Лиды: факт 1-'+D,num(f.L),'',['план на сегодня '+num(Lp),h('span',{},chip(dL,true,v=>num(v)),' ',pc(Lp?dL/Lp:null,1))]),
      tile(dR<0?'Не хватает выручки до плана на сегодня':'Выручка сверх плана на сегодня',mln(Math.abs(dR)),dR<0?'neg':'pos',[gap?'разложение по причинам - ниже, в «Где потеряны деньги»':'для разложения по причинам нужен эталон или база сравнения']),
      tile('Прогноз выручки на '+RU_M[CUR.m],mln(fcR),fcR<s.plan.rev?'neg':'pos',['план месяца '+mln(s.plan.rev)+' · '+pc(s.plan.rev?fcR/s.plan.rev:null,0),'лиды: прогноз '+num(fcL)+' из '+num(s.plan.leads)])));
  }
  const grid=h('div',{class:'twocol'});main.appendChild(grid);
  const cumul=(a,n)=>{const o=[];let t=0;for(let i=0;i<n;i++){t+=a[i]||0;o.push(t);}return o;};
  const revDaily=s.cur.NR.map((v,i)=>v+s.cur.OR[i]);
  lineCard(grid,'Выручка накопительно, ₽','новые + старые продажи',cumul(revDaily,D),s.plan?s.plan.rev:null,mln);
  lineCard(grid,'Лиды накопительно','',cumul(s.cur.L,D),s.plan?s.plan.leads:null,num);
}
function lineCard(host,title,sub,fact,planMonth,fmt){
  const hasPlan=ok(planMonth)&&planMonth>0;
  const card=h('div',{class:'card'},h('h3',{text:title}),sub?h('p',{class:'sub',text:sub}):null,
    h('div',{class:'legend'},h('span',{},h('span',{class:'key-line',style:'border-color:var(--s1)'}),'факт'),h('span',{},h('span',{class:'key-line',style:'border-color:var(--s1);border-top-style:dashed'}),'прогноз по темпу'),
      hasPlan?h('span',{},h('span',{class:'key-line',style:'border-color:var(--ink-2);border-top-style:dashed'}),'план (равномерно)'):null));
  const box=h('div',{class:'chart'});card.appendChild(box);host.appendChild(card);
  requestAnimationFrame(()=>{
    const W=Math.max(box.clientWidth,360),H=230,m={l:64,r:70,t:10,b:28},pw=W-m.l-m.r,ph=H-m.t-m.b,N=CUR.n;
    const fc=fact[D-1]/D*N, top=Math.max(hasPlan?planMonth:0,fc,1)*1.08;
    const X=d=>m.l+(d-1)/(N-1)*pw, Y=v=>m.t+ph-v/top*ph;
    const svg=sv('svg',{viewBox:`0 0 ${W} ${H}`,height:H,role:'img','aria-label':title},box);
    const step=niceStep(top);
    for(let v=0;v<=top;v+=step){sv('line',{x1:m.l,x2:m.l+pw,y1:Y(v),y2:Y(v),stroke:'var(--grid)'},svg);txt(svg,m.l-8,Y(v)+4,axisTxt(v),{'text-anchor':'end'});}
    [1,5,10,15,20,25,N].forEach(d=>txt(svg,X(d),H-8,String(d),{'text-anchor':'middle'}));
    if(hasPlan)sv('line',{x1:X(1),y1:Y(planMonth/N),x2:X(N),y2:Y(planMonth),stroke:'var(--ink-2)','stroke-width':2,'stroke-dasharray':'5 4'},svg);
    sv('path',{d:fact.map((v,i)=>(i?'L':'M')+X(i+1)+','+Y(v)).join(''),fill:'none',stroke:'var(--s1)','stroke-width':2,'stroke-linejoin':'round','stroke-linecap':'round'},svg);
    if(D<N)sv('line',{x1:X(D),y1:Y(fact[D-1]),x2:X(N),y2:Y(fc),stroke:'var(--s1)','stroke-width':2,'stroke-dasharray':'4 4'},svg);
    sv('circle',{cx:X(D),cy:Y(fact[D-1]),r:4.5,fill:'var(--s1)',stroke:'var(--surface)','stroke-width':2},svg);
    if(hasPlan)txt(svg,X(N)+6,Y(planMonth)+4,'план',{});
    if(D<N)txt(svg,X(N)+6,Y(fc)+(hasPlan&&Math.abs(Y(fc)-Y(planMonth))<14?16:4),'прогноз',{});
    const cross=sv('line',{y1:m.t,y2:m.t+ph,stroke:'var(--axis)',opacity:0},svg);
    const ov=sv('rect',{x:m.l,y:m.t,width:pw,height:ph,fill:'transparent'},svg);
    ov.addEventListener('pointermove',ev=>{const r=svg.getBoundingClientRect();const d=Math.max(1,Math.min(N,Math.round(((ev.clientX-r.left)*W/r.width-m.l)/pw*(N-1)+1)));
      cross.setAttribute('x1',X(d));cross.setAttribute('x2',X(d));cross.setAttribute('opacity',1);
      const rows=[];const pl=hasPlan?planMonth/N*d:null;
      if(d<=D){rows.push({label:'факт',value:fmt(fact[d-1]),color:'var(--s1)'});if(hasPlan)rows.push({label:'отклонение от плана',value:sgn(fact[d-1]-pl,fmt)+' · '+pc((fact[d-1]-pl)/pl,1)});}
      else rows.push({label:'прогноз по темпу',value:fmt(fact[D-1]/D*d),color:'var(--s1)'});
      if(hasPlan)rows.push({label:'план на этот день',value:fmt(pl),color:'var(--ink-2)'});
      showTip(ev,d+' '+RU_MG[CUR.m],rows);});
    ov.addEventListener('pointerleave',()=>{cross.setAttribute('opacity',0);hideTip();});
  });
}

/* ---------- 2. Воронка ---------- */
const STAGES=[
  {k:'L',name:'Лиды',sub:'обращения',cr:null},
  {k:'B',name:'Постановки',sub:'записи на пробное',cr:'cr1',crName:'лид → постановка'},
  {k:'M',name:'Встречи',sub:'пробные состоялись',cr:'cr2',crName:'постановка → встреча (явка)'},
  {k:'S',name:'Первые продажи',sub:'оплата после пробного',cr:'cr3',crName:'встреча → продажа'},
];
function renderFunnel(main,s,f,B){
  const c=B[state.cmp];
  main.appendChild(h('h2',{text:'Воронка продаж · 1-'+D+' '+RU_MG[CUR.m]}));
  if(!c){main.appendChild(h('div',{class:'info-banner',text:'База сравнения «'+{prev:'Прошлый месяц',dir:'Направление',ref:'Эталон'}[state.cmp]+'» недоступна для этой школы: нет данных. Выберите другую базу вверху.'}));}
  main.appendChild(h('p',{class:'sub',text:c?'Синий - школа. Пунктирный контур - сколько было бы на этапе при тех же лидах, но с конверсиями базы сравнения ('+c.label.toLowerCase()+': '+c.note+'). Контур длиннее полосы - школа теряет людей на этом шаге.':'Синий - школа.'}));
  const fn=h('div',{class:'fn'});
  fn.append(h('div',{class:'hd',text:'Этап'}),h('div',{class:'hd',text:'Школа и база сравнения при тех же лидах'}),h('div',{class:'hd',style:'text-align:right',text:'Школа'}),h('div',{class:'hd',text:c?c.label+(c.noAbs?'':' (факт)'):''}));
  const cu=c&&ok(c.cr1)&&ok(c.cr2)&&ok(c.cr3);
  const exp={L:f.L};if(cu){exp.B=f.L*c.cr1;exp.M=exp.B*c.cr2;exp.S=exp.M*c.cr3;}
  const max=Math.max(f.L,1);
  STAGES.forEach((st,i)=>{
    if(st.cr){
      fn.appendChild(h('div',{class:'cv'},h('div',{class:'arrow',text:'↓ '+st.crName}),
        h('div',{},h('span',{class:'rate',text:pc(f[st.cr])}),c?' vs '+pc(c[st.cr])+' ':'',c?chip(f[st.cr]-c[st.cr],true,pp):null),h('div'),h('div')));
    }
    const fill=h('div',{class:'fill',style:'width:'+(f[st.k]/max*100)+'%'});
    const ghost=i&&cu?h('div',{class:'ghost',style:'width:'+Math.min(100,exp[st.k]/max*100)+'%'}):null;
    const lost=cu?exp[st.k]-f[st.k]:null;
    const cmpTxt=!c?'':c.noAbs?(i&&cu?'при ваших лидах ≈ '+num(exp[st.k]):'нет объёмов'):num(c[st.k])+(i&&cu?' · при ваших лидах ≈ '+num(exp[st.k]):'');
    const row=h('div',{class:'row'},h('div',{class:'st'},st.name,h('small',{text:st.sub})),h('div',{class:'track'},fill,ghost),h('div',{class:'v',text:num(f[st.k])}),h('div',{class:'cmp'},cmpTxt));
    [...row.children].forEach(el=>hover(el,()=>({title:st.name+' · 1-'+D+' '+RU_MG[CUR.m],rows:[
      {label:'школа',value:num(f[st.k]),color:'var(--s1)',rect:true},
      ...(i&&cu?[{label:'при ваших лидах с конверсиями «'+c.short+'»',value:num(exp[st.k])},{label:lost>0?'не хватает людей':'сверх базы',value:num(Math.abs(lost))}]:[]),
      ...(c&&!c.noAbs?[{label:c.label+' (факт)',value:num(c[st.k])}]:[])]})));
    fn.appendChild(row);
  });
  fn.appendChild(h('div',{class:'cv'},h('div',{class:'arrow',text:'сквозная: лид → продажа'}),h('div',{},h('span',{class:'rate',text:pc(f.crT)}),c?' vs '+pc(c.crT)+' ':'',c?chip(f.crT-c.crT,true,pp):null),h('div'),h('div')));
  fn.appendChild(h('div',{class:'sep'}));
  const expNR=cu&&ok(c.chk)?exp.S*c.chk:null;
  const moneyRows=[
    {name:'Средний чек',sub:'новые продажи ₽ / первые продажи',v:f.chk,cv:c?c.chk:null,bar:false},
    {name:'Новые продажи, ₽',sub:'оплаты после пробного',v:f.NR,cv:c&&!c.noAbs?c.NR:null,exp:expNR,bar:true},
    {name:'Старые продажи, ₽',sub:'продления текущих учеников',v:f.OR,cv:c&&!c.noAbs?c.OR:null,bar:true},
  ];
  const mmax=Math.max(f.NR,f.OR,z(expNR),z(c&&c.NR),z(c&&c.OR),1);
  moneyRows.forEach(r=>{
    const track=h('div',{class:'track'});
    if(r.bar){track.appendChild(h('div',{class:'fill',style:'width:'+(r.v/mmax*100)+'%'}));if(ok(r.exp))track.appendChild(h('div',{class:'ghost',style:'width:'+(r.exp/mmax*100)+'%'}));}
    else if(c)track.appendChild(h('div',{style:'font-size:12.5px;color:var(--ink-2);padding-top:3px'},chip(ok(r.v)&&ok(r.cv)?r.v-r.cv:null,true,v=>rub(v)),' к базе «'+c.short+'»'));
    const cmpTxt=!c?'':r.name==='Средний чек'?rub(r.cv):ok(r.exp)?(ok(r.cv)?rub(r.cv)+' · ':'')+'при ваших лидах ≈ '+rub(r.exp):ok(r.cv)?rub(r.cv):'нет значения';
    fn.appendChild(h('div',{class:'row'},h('div',{class:'st'},r.name,h('small',{text:r.sub})),track,h('div',{class:'v',text:rub(r.v)}),h('div',{class:'cmp'},cmpTxt)));
  });
  fn.appendChild(h('div',{class:'cv'},h('div',{class:'arrow',text:'новая выручка на 1 лид'}),h('div',{},h('span',{class:'rate',text:rub(f.rpl)}),c?' vs '+rub(c.rpl)+' ':'',c?chip(ok(f.rpl)&&ok(c.rpl)?f.rpl-c.rpl:null,true,v=>rub(v)):null),h('div'),h('div')));
  main.appendChild(h('div',{class:'card'},fn));
  // все базы рядом
  const rows=[['Лиды','L',num,true],['Постановки','B',num,true],['Встречи','M',num,true],['Первые продажи','S',num,true],
    ['CR лид → постановка','cr1',pc],['CR постановка → встреча','cr2',pc],['CR встреча → продажа','cr3',pc],['CR сквозная лид → продажа','crT',pc],
    ['Средний чек','chk',rub],['Новые продажи','NR',rub,true],['Старые продажи','OR',rub,true],['Новая выручка на 1 лид','rpl',rub]];
  const tb=h('tbody');
  rows.forEach(([n,k,fm,abs])=>{const tr=h('tr',{},h('td',{class:'l first',text:n}),h('td',{style:'font-weight:650',text:fm(f[k])}));
    ['prev','dir','ref'].forEach(b=>{const bb=B[b];const v=bb?bb[k]:null;const show=bb&&!(abs&&bb.noAbs)&&ok(v);
      tr.appendChild(h('td',{},show?fm(v):'-',show&&!abs?h('div',{style:'font-size:11px'},chip(ok(f[k])?f[k]-v:null,true,k==='chk'||k==='rpl'?x=>rub(x):pp)):null));});
    tb.appendChild(tr);});
  main.appendChild(h('div',{class:'card'},h('h3',{text:'Все базы сравнения рядом'}),h('p',{class:'sub',text:'Прошлый месяц и направление - за те же 1-'+D+' дней месяца. Направление: объёмы - в среднем на одну школу, конверсии - по суммам всех школ направления (крупные школы весят больше). Эталон задаёт только конверсии и чек. «-» - нет данных.'}),
    h('div',{class:'tblwrap cmp-tbl',style:'max-height:none'},h('table',{},h('thead',{},h('tr',{},h('th',{class:'l first',text:'Показатель'}),h('th',{text:'Школа'}),h('th',{text:'Прошлый месяц'}),h('th',{text:'Направление'}),h('th',{text:'Эталон'}))),tb))));
}

/* ---------- 3. Где потеряны деньги ---------- */
function renderLoss(main,s,f,B){
  main.appendChild(h('h2',{text:'Где потеряны деньги относительно плана · на '+D+' '+RU_MG[CUR.m]}));
  const seg=h('span',{class:'seg'},...[['ref','эталон'],['prev','прошлый месяц'],['dir','направление']].map(([v,l])=>h('button',{type:'button',class:state.target===v?'on':'',disabled:usable(B[v])?null:'disabled',title:usable(B[v])?null:'нет данных',onclick:()=>{state.target=v;store.set('target',v);render();}},l)));
  main.appendChild(h('div',{class:'toolbar'},h('label',{},'Целевые конверсии и чек',seg),h('span',{style:'font-size:12.5px;color:var(--ink-2)',text:'План задаёт лиды и выручку; конверсии и чек, на которых он «держится», берутся из выбранной базы.'})));
  const t=B[state.target], g=planGap(s,f,t);
  if(!g){main.appendChild(h('div',{class:'info-banner',text:!s.plan?'Нет плана на '+RU_M[CUR.m]+' для этой школы - разложить отклонение не от чего.':'Для выбранной базы нет конверсий или чека - выберите другую базу.'}));return;}
  const total=f.rev-g.Rp;
  const card=h('div',{class:'card'});main.appendChild(card);
  card.appendChild(h('div',{class:'legend wf-legend'},h('span',{},h('i',{style:'background:var(--ink-2)'}),'план / факт'),h('span',{},h('i',{style:'background:var(--bad)'}),'потеряно'),h('span',{},h('i',{style:'background:var(--good)'}),'сверх плана')));
  const box=h('div',{class:'chart'});card.appendChild(box);
  requestAnimationFrame(()=>{
    const items=[{name:'План на '+D+' '+RU_MG[CUR.m],v:g.Rp,abs:true},...g.factors.map(x=>({name:x.name,v:x.v,f:x})),{name:'Факт',v:f.rev,abs:true}];
    const W=Math.max(box.clientWidth,640),H=300,m={l:70,r:12,t:22,b:46},pw=W-m.l-m.r,ph=H-m.t-m.b,n=items.length,band=pw/n,bw=Math.min(56,band*.55);
    let run=0;const bars=items.map(it=>{if(it.abs){run=it.v;return {it,lo:0,hi:it.v};}const a=run,b=run+it.v;run=b;return {it,lo:Math.min(a,b),hi:Math.max(a,b)};});
    const top=Math.max(1,...bars.map(b=>b.hi))*1.08, lo0=Math.min(0,...bars.map(b=>b.lo));
    const Y=v=>m.t+ph-(v-lo0)/(top-lo0)*ph;
    const svg=sv('svg',{viewBox:`0 0 ${W} ${H}`,height:H,role:'img','aria-label':'Разложение отклонения выручки от плана'},box);
    const step=niceStep(top);
    for(let v=0;v<=top;v+=step){sv('line',{x1:m.l,x2:m.l+pw,y1:Y(v),y2:Y(v),stroke:'var(--grid)'},svg);txt(svg,m.l-8,Y(v)+4,axisTxt(v),{'text-anchor':'end'});}
    bars.forEach((b,i)=>{const x=m.l+i*band+(band-bw)/2;const col=b.it.abs?'var(--ink-2)':b.it.v<0?'var(--bad)':'var(--good)';
      sv('rect',{x,y:Y(b.hi),width:bw,height:Math.max(1,Y(b.lo)-Y(b.hi)),rx:3,fill:col},svg);
      if(i<bars.length-1){const yy=Y(b.it.abs?b.hi:(b.it.v<0?b.lo:b.hi));sv('line',{x1:x+bw,x2:x+band,y1:yy,y2:yy,stroke:'var(--axis)','stroke-dasharray':'3 3'},svg);}
      const cm=v=>v<1e4?rub(v):mln(v);const lab=b.it.abs?mln(b.it.v):(b.it.v<0?'−':'+')+cm(Math.abs(b.it.v));
      txt(svg,x+bw/2,Y(b.hi)-6,lab,{'text-anchor':'middle','font-weight':b.it.abs?650:400});
      const words=b.it.name.split(' ');let l1='',l2='';words.forEach(w=>{if((l1+' '+w).trim().length<=14&&!l2)l1=(l1+' '+w).trim();else l2=(l2+' '+w).trim();});
      txt(svg,x+bw/2,H-m.b+16,l1,{'text-anchor':'middle'});if(l2)txt(svg,x+bw/2,H-m.b+30,l2,{'text-anchor':'middle'});
      const hit=sv('rect',{x:m.l+i*band,y:m.t,width:band,height:ph,fill:'transparent'},svg);
      hit.addEventListener('pointermove',ev=>{const rows=b.it.abs?[{label:'выручка',value:rub(b.it.v)}]:[{label:b.it.v<0?'потеряно':'получено сверх плана',value:rub(Math.abs(b.it.v))},{label:'доля отклонения',value:total?pc(b.it.v/total,0):'н/д'},{label:'как считается',value:b.it.f.how}];showTip(ev,b.it.name,rows);});
      hit.addEventListener('pointerleave',hideTip);});
  });
  const lossSum=g.factors.filter(x=>x.v<0).reduce((a,x)=>a+x.v,0);
  const fsum=g.factors.reduce((a,x)=>a+x.v,0);
  const tb=h('tbody');
  const factVal={L:num(f.L)+' из '+num(g.Lp),cr1:pc(f.cr1)+' vs '+pc(t.cr1),cr2:pc(f.cr2)+' vs '+pc(t.cr2),cr3:pc(f.cr3)+' vs '+pc(t.cr3),chk:rub(f.chk)+' vs '+rub(t.chk),old:rub(f.OR)+' vs '+rub(g.oldT)};
  g.factors.slice().sort((a,b)=>a.v-b.v).forEach(x=>tb.appendChild(h('tr',{},h('td',{class:'l first',text:x.name}),h('td',{class:'l',text:factVal[x.k]}),
    h('td',{style:'font-weight:650;color:'+(x.v<0?'var(--bad)':'var(--good)'),text:sgn(x.v,rub)}),h('td',{text:x.v<0&&lossSum?pc(x.v/lossSum,0):''}),h('td',{class:'l',style:'white-space:normal;color:var(--ink-2);font-size:12px',text:x.how}))));
  tb.appendChild(h('tr',{class:'tot'},h('td',{class:'l first',text:'Итого: факт − план'}),h('td',{class:'l',text:rub(f.rev)+' vs '+rub(g.Rp)}),h('td',{style:'font-weight:650',text:sgn(total,rub)}),h('td'),
    h('td',{class:'l',style:'white-space:normal;color:var(--ink-2);font-size:12px',text:Math.abs(fsum-total)<1?'сумма факторов равна отклонению':'план выручки меньше новых продаж по цели - сумма факторов отличается от отклонения на '+rub(Math.abs(fsum-total))})));
  main.appendChild(h('div',{class:'card'},h('h3',{text:'Потери по шагам воронки'}),
    h('p',{class:'sub',text:'Отрицательные значения - сколько выручки не получено из-за этого шага, положительные - что компенсировало. План новых продаж на сегодня по цели: '+rub(g.newT)+', план продаж текущим ученикам: '+rub(g.oldT)+' (остаток плана выручки).'}),
    h('div',{class:'tblwrap',style:'max-height:none'},h('table',{},h('thead',{},h('tr',{},h('th',{class:'l first',text:'Шаг'}),h('th',{class:'l',text:'Факт vs цель'}),h('th',{text:'Влияние на выручку'}),h('th',{text:'Доля потерь'}),h('th',{class:'l',text:'Как считается'}))),tb))));
}

/* ---------- 4. Методика и формат источника ---------- */
function formatCard(){
  const sh=META.sheets, tbl=(head,rows)=>h('div',{class:'tblwrap',style:'max-height:none;margin:6px 0 12px'},h('table',{},h('thead',{},h('tr',{},...head.map(x=>h('th',{class:'l',text:x})))),h('tbody',{},...rows.map(r=>h('tr',{},...r.map(x=>h('td',{class:'l',text:x})))))));
  return h('div',{class:'card method'},h('h2',{text:'Формат таблицы-источника'}),
    h('p',{text:'Одна Google-таблица с тремя листами (план и эталон можно держать в других таблицах - ссылки задаются в «Настройках»). Столбцы ищутся по заголовкам, порядок и регистр не важны; лишние столбцы не мешают.'}),
    h('h3',{text:'Лист «'+sh.funnel+'» - строка = школа за один день'}),
    tbl(['Дата','Город','Направление','Лиды','Постановки','Встречи','Первые продажи','Новые продажи','Старые продажи'],[['19.11.2026','Казань','Гитара','6','4','3','1','26 000','48 000']]),
    h('p',{text:'Нужны данные текущего и прошлого месяца. Повторяющиеся строки одной школы за один день складываются. «Город» - как в отчёте по выручке, для второй школы в городе - с номером («Москва 2»).'}),
    h('h3',{text:'Лист «'+sh.plan+'» - план школы на месяц'}),
    tbl(['Месяц','Город','Направление','План лидов','План выручки'],[['11.2026 или ноябрь 2026','Казань','Гитара','180','2 400 000']]),
    h('h3',{text:'Лист «'+sh.ref+'» - эталонные конверсии и чек'}),
    tbl(['Направление','Лид → постановка','Постановка → встреча','Встреча → продажа','Средний чек'],[['Гитара','62%','75%','40%','28 000'],['Все (для направлений без своей строки)','60%','72%','38%','27 000']]),
  );
}
function renderMethod(main){
  const ul=items=>h('ul',{},...items.map(x=>h('li',{text:x})));
  main.appendChild(h('div',{class:'card method'},h('h2',{text:'Как считается'}),
    ul(['Все показатели - накопительно с 1-го числа по срез (вчера или последний день с данными в воронке). Прошлый месяц и направление - за те же дни, чтобы сравнение было честным.',
      'Конверсии: постановки / лиды, встречи / постановки, первые продажи / встречи. Средний чек = новые продажи ₽ / первые продажи. Выручка = новые + старые продажи.',
      'План на сегодня = план месяца × прошедшие дни / дней в месяце (равномерно). Прогноз месяца - по темпу с 1-го числа.',
      'Потери считаются цепными подстановками от плана к факту в порядке: чек → встреча→продажа → постановка→встреча → лид→постановка → лиды. Потери от лидов - по текущим конверсиям и текущему чеку, потери на конверсиях - на плановом числе лидов. Сумма факторов равна отклонению выручки от плана.',
      'План новых продаж = лиды по плану × целевые конверсии × целевой чек; план продаж текущим ученикам = план выручки − план новых продаж.',
      'Лаг воронки: лид конца периода ещё не дошёл до пробного, поэтому в середине месяца конверсии ниже итоговых. Сравнение с прошлым месяцем и направлением за те же дни это выравнивает, с эталоном - нет.'])));
  main.appendChild(formatCard());
}

/* ---------- каркас ---------- */
function setAsof(){const el=document.getElementById('asof');el.textContent='';
  el.append('Срез ',h('b',{text:D+'.'+String(CUR.m+1).padStart(2,'0')+'.'+CUR.y}),META.shifted?' (последний день с данными в воронке)':' (вчера)',' · день '+D+' из '+CUR.n);}
function render(){
  const main=document.getElementById('main'),y=scrollY;main.textContent='';
  if(!META.configured){
    const fl=document.querySelector('header .filters');if(fl)fl.style.display='none';
    main.appendChild(h('h1',{style:'margin:14px 0 4px;font-size:22px',text:'Экономика школы'}));
    main.appendChild(h('div',{class:'info-banner',text:'Источник данных ещё не подключён. Подготовьте Google-таблицу в формате ниже, откройте её на чтение сервисному аккаунту и укажите ссылку в «Настройках» → «Источники и параметры: Экономика школы». Затем нажмите «Обновить».'}));
    main.appendChild(formatCard());return;
  }
  setAsof();
  const s=SCHOOLS.find(x=>x.id===state.school);
  if(!s){main.appendChild(h('div',{class:'info-banner',text:'В воронке нет школ с данными за '+RU_M[CUR.m]+' и '+RU_M[PREV.m]+'.'}));main.appendChild(formatCard());return;}
  const f=agg(s.cur,D), B=bases(s);
  document.querySelectorAll('#f-cmp button').forEach(b=>{b.classList.toggle('on',b.dataset.v===state.cmp);b.disabled=!B[b.dataset.v];b.title=B[b.dataset.v]?'':'нет данных для этой школы';});
  main.appendChild(h('h1',{style:'margin:14px 0 2px;font-size:22px',text:s.name+' · '+s.dir}));
  main.appendChild(h('p',{class:'sub',text:'Экономика школы за '+RU_M[CUR.m]+' '+CUR.y+': воронка продаж, план и потери.'}));
  renderPlan(main,s,f,planGap(s,f,B[state.target]));
  renderFunnel(main,s,f,B);
  renderLoss(main,s,f,B);
  renderMethod(main);
  scrollTo(0,y);
}
function init(){
  const sel=document.getElementById('f-school');
  if(sel){
    [...new Set(SCHOOLS.map(x=>x.dir))].forEach(dir=>{const g=h('optgroup',{label:dir});SCHOOLS.filter(x=>x.dir===dir).forEach(x=>g.appendChild(h('option',{value:x.id,selected:x.id===state.school?'selected':null},x.name)));sel.appendChild(g);});
    sel.addEventListener('change',e=>{state.school=e.target.value;store.set('school',state.school);render();});
  }
  document.querySelectorAll('#f-cmp button').forEach(b=>b.addEventListener('click',()=>{state.cmp=b.dataset.v;store.set('cmp',state.cmp);render();}));
  const th=document.getElementById('theme');
  if(th)th.addEventListener('click',()=>{const r=document.documentElement;const dark=r.dataset.theme?r.dataset.theme==='dark':matchMedia('(prefers-color-scheme: dark)').matches;r.dataset.theme=dark?'light':'dark';try{localStorage.setItem('ns_dash_theme',JSON.stringify(r.dataset.theme));}catch(e){}render();});
  try{const t=JSON.parse(localStorage.getItem('ns_dash_theme'));if(t)document.documentElement.dataset.theme=t;}catch(e){}
  let rt;addEventListener('resize',()=>{clearTimeout(rt);rt=setTimeout(render,150);});
  render();
}
init();
