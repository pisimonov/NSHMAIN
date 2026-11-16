"use strict";
const DATA = window.DASH_DATA, M = DATA.meta, S = DATA.schools;
const RU_M = ['январь','февраль','март','апрель','май','июнь','июль','август','сентябрь','октябрь','ноябрь','декабрь'];
const RU_MG = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
const RU_MD = ['январю','февралю','марту','апрелю','маю','июню','июлю','августу','сентябрю','октябрю','ноябрю','декабрю'];
const RU_MP = ['январе','феврале','марте','апреле','мае','июне','июле','августе','сентябре','октябре','ноябре','декабре'];
const RU_MS = ['янв','фев','мар','апр','май','июн','июл','авг','сен','окт','ноя','дек'];
const WD = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'];
const ym = m => [+m.slice(0,4), +m.slice(5,7)];
const calDays = m => { const [y,mo]=ym(m); return new Date(y,mo,0).getDate(); };
const cap = s => s.charAt(0).toUpperCase()+s.slice(1);
const mLabel = m => { const [y,mo]=ym(m); return cap(RU_M[mo-1])+' '+y; };
const mGen = m => { const [y,mo]=ym(m); return RU_MG[mo-1]+' '+y; };
const mDat = m => { const [y,mo]=ym(m); return RU_MD[mo-1]+' '+y; };
const mPrep = m => { const [y,mo]=ym(m); return RU_MP[mo-1]+' '+y; };
const mShort = m => { const [y,mo]=ym(m); return RU_MS[mo-1]+' '+String(y).slice(2); };
const dmy = (m,d) => { const [y,mo]=ym(m); return String(d).padStart(2,'0')+'.'+String(mo).padStart(2,'0')+'.'+y; };
const dm = (m,d) => { const [,mo]=ym(m); return d+' '+RU_MG[mo-1]; };
const wd = (m,d) => { const [y,mo]=ym(m); return WD[new Date(y,mo-1,d).getDay()]; };
const isWeekend = (m,d) => { const [y,mo]=ym(m); const g=new Date(y,mo-1,d).getDay(); return g===0||g===6; };

const CUR=M.cur, PM=M.pm, LY=M.ly;
const AS_OF = +M.asOf.slice(8,10), TODAY = +M.today.slice(8,10);
const NCUR = calDays(CUR), NPM = calDays(PM), NLY = calDays(LY);
const HAS_PM = !!DATA.daily[PM], HAS_LY = !!DATA.daily[LY];

/* ---------- форматирование ---------- */
const nf0 = new Intl.NumberFormat('ru-RU',{maximumFractionDigits:0});
const ok = v => v!=null && isFinite(v);
const rub = v => ok(v) ? nf0.format(Math.round(v)) : 'н/д';
const sRub = v => !ok(v) ? 'н/д' : (v>0?'+':v<0?'-':'')+nf0.format(Math.abs(Math.round(v)));
const pct = (v,d=1) => !ok(v) ? 'н/д' : (v>0?'+':v<0?'-':'')+Math.abs(v*100).toFixed(d).replace('.',',')+'%';
const mln = v => !ok(v) ? 'н/д' : (v/1e6).toFixed(Math.abs(v)>=1e8?1:2).replace('.',',')+' млн ₽';
const mlnS = v => !ok(v) ? 'н/д' : (v>0?'+':v<0?'-':'')+(Math.abs(v)/1e6).toFixed(2).replace('.',',')+' млн ₽';
const axisFmt = v => v===0 ? '0' : v>=1e6 ? (+(v/1e6).toFixed(1)).toString().replace('.',',')+' млн' : v>=1e3 ? (+(v/1e3).toFixed(0))+' тыс' : String(v);
const sgnCls = v => !ok(v) ? '' : v>0 ? 'pos' : v<0 ? 'neg' : '';

/* ---------- состояние ---------- */
const store = { get(k,d){ try{ const v=localStorage.getItem('ns_dash_'+k); return v==null?d:JSON.parse(v);}catch(e){return d;} },
                set(k,v){ try{ localStorage.setItem('ns_dash_'+k, JSON.stringify(v)); }catch(e){} } };
const state = {
  scope: store.get('scope','all'), tab: store.get('tab','overview'),
  country:'all', dir:'all', cut: AS_OF,
  thr: (M.defaults&&M.defaults.thr)||20, minBase: (M.defaults&&M.defaults.minBase!=null)?M.defaults.minBase:100000, silentN: (M.defaults&&M.defaults.silentN)||5,
  flagFilter:'all', search:'', onlyActive:true, sorts:{}
};

/* ---------- DOM-помощники (имена из данных - только через textContent) ---------- */
function h(tag, attrs, ...kids){
  const e = document.createElement(tag);
  if (attrs) for (const k in attrs){
    const v = attrs[k]; if (v==null || v===false) continue;
    if (k==='class') e.className=v; else if (k==='text') e.textContent=v;
    else if (k==='style') e.style.cssText=v; else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
    else e.setAttribute(k, v);
  }
  for (const c of kids.flat()){ if (c==null||c===false) continue; e.appendChild(typeof c==='string'||typeof c==='number' ? document.createTextNode(String(c)) : c); }
  return e;
}
const SVGNS='http://www.w3.org/2000/svg';
function s(tag, attrs, parent){ const e=document.createElementNS(SVGNS,tag); for (const k in attrs) e.setAttribute(k, attrs[k]); if (parent) parent.appendChild(e); return e; }
function txt(parent, x, y, str, attrs){ const t=s('text', Object.assign({x,y},attrs||{}), parent); t.textContent=str; return t; }

/* ---------- расчётное ядро ---------- */
function selection(){
  const out=[];
  S.forEach((sc,i)=>{
    if (state.scope==='own' && !sc.own) return;
    if (state.country==='ru' && sc.co!=='Россия') return;
    if (state.country==='foreign' && sc.co==='Россия') return;
    if (state.dir!=='all' && sc.d!==state.dir) return;
    out.push(i);
  });
  return out;
}
const cumN = (a,n) => { if(!a) return 0; let t=0; const k=Math.min(n,a.length); for(let i=0;i<k;i++) t+=a[i]; return t; };
const totA = a => a ? a.reduce((x,y)=>x+y,0) : 0;
function silentDays(a, D){ if(!a) return D; let n=0; for(let d=D; d>=1; d--){ if((a[d-1]||0)>0) break; n++; } return n; }

/* Месяц завершён (срез - последний день): сравниваем с полными месяцами базы, иначе - с теми же числами. */
const isFull = D => D>=NCUR;
const baseLbl = D => isFull(D) ? 'весь месяц' : '1-'+D;
function schoolStat(i, D){
  const c=DATA.daily[CUR][i], p=HAS_PM?DATA.daily[PM][i]:null, l=HAS_LY?DATA.daily[LY][i]:null;
  const full=isFull(D);
  return { i, fact:cumN(c,D), yday:c&&c[D-1]||0, pmD:full?totA(p):cumN(p,D), pmT:totA(p), lyD:full?totA(l):cumN(l,D), lyT:totA(l),
           inPM:!!p, inLY:!!l, silent:silentDays(c,D) };
}
function derive(a){
  a.dPM = HAS_PM ? a.fact-a.pmD : null; a.rPM = a.pmD>0 ? a.fact/a.pmD-1 : null;
  a.dLY = HAS_LY ? a.fact-a.lyD : null; a.rLY = a.lyD>0 ? a.fact/a.lyD-1 : null;
  a.fcPM = a.pmD>0 ? a.pmT*a.fact/a.pmD : null;
  a.fcLY = a.lyD>0 ? a.lyT*a.fact/a.lyD : null;
  a.flagPM = flagOf(a.fact, a.pmD); a.flagLY = flagOf(a.fact, a.lyD);
  return a;
}
function flagOf(fact, base){
  if (base<=0) return fact>0 ? 'new' : 'none';
  if (base < state.minBase) return 'small';
  const r = fact/base-1, t = state.thr/100;
  return r<=-t ? 'down' : r>=t ? 'up' : 'ok';
}
function aggregate(stats){
  const a={fact:0,yday:0,pmD:0,pmT:0,lyD:0,lyT:0,n:0,act:0};
  for(const x of stats){ a.fact+=x.fact; a.yday+=x.yday; a.pmD+=x.pmD; a.pmT+=x.pmT; a.lyD+=x.lyD; a.lyT+=x.lyT; a.n++; if(x.fact>0) a.act++; }
  return derive(a);
}
function groupBy(stats, keyFn){
  const m=new Map();
  for(const x of stats){ const k=keyFn(S[x.i]); if(!m.has(k)) m.set(k,[]); m.get(k).push(x); }
  return [...m.entries()].map(([k,arr])=>Object.assign(aggregate(arr),{key:k, items:arr}));
}
function seriesSum(month, idx){
  const n = calDays(month), out=new Array(n).fill(0), src=DATA.daily[month]; if(!src) return null;
  let maxLen=0;
  for(const i of idx){ const a=src[i]; if(!a) continue; maxLen=Math.max(maxLen,a.length); for(let d=0; d<a.length && d<n; d++) out[d]+=a[d]; }
  return {daily:out, len:maxLen};
}
const cumArr = a => { let t=0; return a.map(v=>t+=v); };
/* доля месяца-базы, набранная к дню d; последний день текущего месяца = весь месяц базы */
function refShare(refCum, refTot, d){ if(d>=NCUR) return 1; return refTot>0 ? refCum[Math.min(d,refCum.length)-1]/refTot : 0; }

/* ---------- тултип ---------- */
const tip = document.getElementById('tip');
function showTip(ev, title, rows){
  tip.textContent='';
  tip.appendChild(h('div',{class:'t',text:title}));
  for(const r of rows){
    const key = h('span',{class:'k'}, r.color ? h('span',{class: r.dash?'key-dash':'key-line', style:'border-color:'+r.color}) : null, r.label);
    tip.appendChild(h('div',{class:'r'}, key, h('b',{text:r.value, class:r.cls||null})));
  }
  tip.style.display='block';
  const w=tip.offsetWidth, hh=tip.offsetHeight;
  let x=ev.clientX+14, y=ev.clientY+14;
  if(x+w>innerWidth-8) x=ev.clientX-w-14;
  if(y+hh>innerHeight-8) y=ev.clientY-hh-14;
  tip.style.left=x+'px'; tip.style.top=y+'px';
}
const hideTip = () => tip.style.display='none';

/* ---------- графики ---------- */
function niceStep(max, count){ const raw=max/count; const p=Math.pow(10,Math.floor(Math.log10(raw||1))); const n=raw/p; return (n<=1?1:n<=2?2:n<=2.5?2.5:n<=5?5:10)*p; }
function yScaleInfo(max){ if(max<=0) max=1; const st=niceStep(max,5); const top=Math.ceil(max/st)*st; const t=[]; for(let v=0; v<=top+st/1e6; v+=st) t.push(v); return {top, ticks:t}; }

function lineChart(host, cfg){
  host.textContent='';
  const W = Math.max(host.clientWidth, 520), H = cfg.height||320;
  const m = {l:58, r:170, t:14, b:30};
  const pw = W-m.l-m.r, ph = H-m.t-m.b;
  const svg = s('svg',{viewBox:`0 0 ${W} ${H}`, height:H, role:'img', 'aria-label':cfg.aria||''}, host);
  const X = d => m.l + (d-1)/(cfg.xMax-1)*pw;
  let ymax = 0; for(const se of cfg.series) for(const v of se.data) if(ok(v)) ymax=Math.max(ymax,v);
  const ys = yScaleInfo(ymax*1.04); const Y = v => m.t + ph - v/ys.top*ph;
  for(const t of ys.ticks){ s('line',{x1:m.l,x2:m.l+pw,y1:Y(t),y2:Y(t),stroke:'var(--grid)','stroke-width':1},svg); txt(svg,m.l-8,Y(t)+4,axisFmt(t),{'text-anchor':'end'}); }
  s('line',{x1:m.l,x2:m.l+pw,y1:Y(0),y2:Y(0),stroke:'var(--axis)','stroke-width':1},svg);
  for(let d=1; d<=cfg.xMax; d++){ if(d===1||d%5===0||d===cfg.xMax) txt(svg,X(d),H-m.b+16,String(d),{'text-anchor':'middle'}); }
  if(cfg.cut){ const cx=X(cfg.cut); s('line',{x1:cx,x2:cx,y1:m.t,y2:m.t+ph,stroke:'var(--axis)','stroke-width':1},svg); txt(svg,cx+4,m.t+10,cfg.cutLabel||'',{}); }
  const ends=[];
  for(const se of cfg.series){
    let dstr='', pen=false, last=null;
    se.data.forEach((v,k)=>{ const d=k+1; if(!ok(v)){pen=false;return;} dstr+=(pen?'L':'M')+X(d).toFixed(1)+','+Y(v).toFixed(1); pen=true; last=[d,v]; });
    s('path',{d:dstr,fill:'none',stroke:se.color,'stroke-width':se.width||2,'stroke-linejoin':'round','stroke-linecap':'round','stroke-dasharray':se.dash?'5 4':'none',opacity:se.opacity||1},svg);
    if(last && se.endLabel){ ends.push({x:X(last[0]), y:Y(last[1]), d:last[0], se, v:last[1]}); }
  }
  // подписи концов: справа - с выносками, не на краю - у точки
  const right = ends.filter(e=>e.d>=cfg.xMax-1).sort((a,b)=>a.y-b.y);
  let prev=-1e9; for(const e of right){ e.ly=Math.max(e.y, prev+28); prev=e.ly; }
  const overflow = right.length? right[right.length-1].ly-(m.t+ph) : 0; if(overflow>0) right.forEach(e=>e.ly-=overflow);
  for(const e of right){
    const lx=m.l+pw+14;
    s('circle',{cx:e.x,cy:e.y,r:4,fill:e.se.color,stroke:'var(--surface)','stroke-width':2},svg);
    s('path',{d:`M${e.x+5},${e.y}L${lx-4},${e.ly}`,stroke:'var(--axis)','stroke-width':1,fill:'none'},svg);
    txt(svg,lx,e.ly-2,e.se.endLabel,{class:'lab'});
    txt(svg,lx,e.ly+12,mln(e.v),{class:'lab-strong'});
  }
  for(const e of ends.filter(e=>e.d<cfg.xMax-1)){
    s('circle',{cx:e.x,cy:e.y,r:4.5,fill:e.se.color,stroke:'var(--surface)','stroke-width':2},svg);
    txt(svg,e.x-8,e.y-20,e.se.endLabel,{class:'lab','text-anchor':'end'});
    txt(svg,e.x-8,e.y-6,mln(e.v),{class:'lab-strong','text-anchor':'end'});
  }
  // слой наведения
  const cross = s('line',{y1:m.t,y2:m.t+ph,stroke:'var(--ink-2)','stroke-width':1,opacity:0},svg);
  const dots = cfg.series.map(se=>s('circle',{r:4,fill:se.color,stroke:'var(--surface)','stroke-width':2,opacity:0},svg));
  const ov = s('rect',{x:m.l-6,y:m.t,width:pw+12,height:ph,fill:'transparent'},svg);
  ov.addEventListener('pointermove',ev=>{
    const r=svg.getBoundingClientRect(); const sx=(ev.clientX-r.left)*W/r.width;
    const d=Math.max(1,Math.min(cfg.xMax,Math.round((sx-m.l)/pw*(cfg.xMax-1)+1)));
    cross.setAttribute('x1',X(d)); cross.setAttribute('x2',X(d)); cross.setAttribute('opacity',.5);
    cfg.series.forEach((se,k)=>{ const v=se.data[d-1]; if(ok(v)){dots[k].setAttribute('cx',X(d));dots[k].setAttribute('cy',Y(v));dots[k].setAttribute('opacity',1);} else dots[k].setAttribute('opacity',0); });
    const t=cfg.tooltip(d); showTip(ev,t.title,t.rows);
  });
  ov.addEventListener('pointerleave',()=>{ cross.setAttribute('opacity',0); dots.forEach(x=>x.setAttribute('opacity',0)); hideTip(); });
}

function dailyChart(host, cfg){
  host.textContent='';
  const W=Math.max(host.clientWidth,520), H=cfg.height||240, m={l:58,r:170,t:12,b:30};
  const pw=W-m.l-m.r, ph=H-m.t-m.b, n=cfg.xMax, band=pw/n;
  const svg=s('svg',{viewBox:`0 0 ${W} ${H}`,height:H,role:'img','aria-label':cfg.aria||''},host);
  let ymax=0; for(const v of cfg.bars) if(ok(v)) ymax=Math.max(ymax,v); for(const se of cfg.dots) for(const v of se.data) if(ok(v)) ymax=Math.max(ymax,v);
  const ys=yScaleInfo(ymax*1.05), Y=v=>m.t+ph-v/ys.top*ph, cx=d=>m.l+(d-.5)*band;
  for(const t of ys.ticks){ s('line',{x1:m.l,x2:m.l+pw,y1:Y(t),y2:Y(t),stroke:'var(--grid)','stroke-width':1},svg); txt(svg,m.l-8,Y(t)+4,axisFmt(t),{'text-anchor':'end'}); }
  for(let d=1; d<=n; d++){ if(cfg.weekend && cfg.weekend(d)) s('rect',{x:m.l+(d-1)*band,y:m.t,width:band,height:ph,fill:'var(--surface-2)',opacity:.6},svg); }
  for(const t of ys.ticks){ s('line',{x1:m.l,x2:m.l+pw,y1:Y(t),y2:Y(t),stroke:'var(--grid)','stroke-width':1},svg); }
  const bw=Math.min(18, band*0.56);
  const rects=[];
  for(let d=1; d<=n; d++){
    const v=cfg.bars[d-1];
    if(ok(v) && v>0){
      const x=cx(d)-bw/2, y=Y(v), hgt=Y(0)-y, r=Math.min(4,hgt);
      const p=`M${x},${Y(0)}V${y+r}Q${x},${y} ${x+r},${y}H${x+bw-r}Q${x+bw},${y} ${x+bw},${y+r}V${Y(0)}Z`;
      rects.push(s('path',{d:p,fill:'var(--s1)',opacity:cfg.partial===d?0.35:1},svg));
    }
    if(d===1||d%5===0||d===n) txt(svg,cx(d),H-m.b+16,String(d),{'text-anchor':'middle'});
  }
  s('line',{x1:m.l,x2:m.l+pw,y1:Y(0),y2:Y(0),stroke:'var(--axis)','stroke-width':1},svg);
  for(const se of cfg.dots){ se.data.forEach((v,k)=>{ if(ok(v)&&k<n) s('circle',{cx:cx(k+1),cy:Y(v),r:4,fill:se.color,stroke:'var(--surface)','stroke-width':2},svg); }); }
  if(cfg.partial) txt(svg,cx(cfg.partial),Y(cfg.bars[cfg.partial-1]||0)-8,'идёт',{'text-anchor':'middle'});
  const hl=s('rect',{y:m.t,height:ph,width:band,fill:'var(--ink)',opacity:0},svg);
  const ov=s('rect',{x:m.l,y:m.t,width:pw,height:ph,fill:'transparent'},svg);
  ov.addEventListener('pointermove',ev=>{
    const r=svg.getBoundingClientRect(); const sx=(ev.clientX-r.left)*W/r.width;
    const d=Math.max(1,Math.min(n,Math.floor((sx-m.l)/band)+1));
    hl.setAttribute('x',m.l+(d-1)*band); hl.setAttribute('opacity',.06);
    const t=cfg.tooltip(d); showTip(ev,t.title,t.rows);
  });
  ov.addEventListener('pointerleave',()=>{hl.setAttribute('opacity',0);hideTip();});
}

function monthChart(host, items){
  host.textContent='';
  const W=Math.max(host.clientWidth,520), H=280, m={l:58,r:16,t:26,b:34};
  const pw=W-m.l-m.r, ph=H-m.t-m.b, n=items.length, band=pw/n;
  const svg=s('svg',{viewBox:`0 0 ${W} ${H}`,height:H,role:'img','aria-label':'Выручка по месяцам'},host);
  let ymax=0; for(const it of items){ for(const v of [it.value,it.fcHi]) if(ok(v)) ymax=Math.max(ymax,v); }
  const ys=yScaleInfo(ymax*1.08), Y=v=>m.t+ph-v/ys.top*ph;
  for(const t of ys.ticks){ s('line',{x1:m.l,x2:m.l+pw,y1:Y(t),y2:Y(t),stroke:'var(--grid)','stroke-width':1},svg); txt(svg,m.l-8,Y(t)+4,axisFmt(t),{'text-anchor':'end'}); }
  const bw=Math.min(24, band*0.55);
  const col=(x,y0,y1,fill,op)=>{ const hgt=y0-y1; if(hgt<=0) return; const r=Math.min(4,hgt); s('path',{d:`M${x},${y0}V${y1+r}Q${x},${y1} ${x+r},${y1}H${x+bw-r}Q${x+bw},${y1} ${x+bw},${y1+r}V${y0}Z`,fill,opacity:op||1},svg); };
  items.forEach((it,k)=>{
    const x=m.l+k*band+(band-bw)/2;
    if(it.missing){ txt(svg,x+bw/2,Y(0)-20,'нет',{'text-anchor':'middle'}); txt(svg,x+bw/2,Y(0)-8,'данных',{'text-anchor':'middle'}); }
    else {
      if(ok(it.fcHi)) { const top=Y(it.fcHi); s('rect',{x:x,y:top,width:bw,height:Y(it.value)-top-2,fill:'var(--s1-light)',rx:3},svg);
                         if(ok(it.fcLo)) s('line',{x1:x-3,x2:x+bw+3,y1:Y(it.fcLo),y2:Y(it.fcLo),stroke:'var(--ink-2)','stroke-width':1.5},svg); }
      col(x,Y(0),Y(it.value),it.current?'var(--s1)':'var(--s1)',it.current?1:0.85);
      if(it.label2) txt(svg,x+bw/2,Y(ok(it.fcHi)?it.fcHi:it.value)-6,it.label2,{'text-anchor':'middle',class:'lab'});
    }
    txt(svg,x+bw/2,H-m.b+16,it.label,{'text-anchor':'middle'});
  });
  s('line',{x1:m.l,x2:m.l+pw,y1:Y(0),y2:Y(0),stroke:'var(--axis)','stroke-width':1},svg);
  const ov=s('g',{},svg);
  items.forEach((it,k)=>{
    const r=s('rect',{x:m.l+k*band,y:m.t,width:band,height:ph,fill:'transparent'},ov);
    r.addEventListener('pointermove',ev=>showTip(ev,it.title,it.tip));
    r.addEventListener('pointerleave',hideTip);
  });
}

/* ---------- таблицы ---------- */
function flagChip(f, r){
  if(f==='down') return h('span',{class:'chip down',title:'Хуже на '+state.thr+'% и более'},'▼ хуже');
  if(f==='up') return h('span',{class:'chip up',title:'Лучше на '+state.thr+'% и более'},'▲ лучше');
  if(f==='small') return h('span',{class:'chip small',title:'База меньше '+rub(state.minBase)+' ₽ - флаг не ставится'},'мала база');
  if(f==='new') return h('span',{class:'chip new',title:'В базовом периоде продаж не было'},'нет базы');
  return h('span',{class:'muted'},'');
}
function deltaCell(v, isPct){ return h('td',{class:sgnCls(v)}, isPct?pct(v):sRub(v)); }

/* cols: {key,label,cls,group,sort(row)->val,cell(row)->td|string,first} */
function dataTable(host, id, cols, rows, opts){
  opts=opts||{};
  const st = state.sorts[id] || opts.defaultSort || {key:cols[1].key, dir:-1};
  const col = cols.find(c=>c.key===st.key) || cols[0];
  const sv = col.sort || (r=>r[col.key]);
  rows = rows.slice().sort((a,b)=>{ const x=sv(a), y=sv(b); const nx=x==null||(typeof x==='number'&&!isFinite(x)), ny=y==null||(typeof y==='number'&&!isFinite(y)); if(nx&&ny) return 0; if(nx) return 1; if(ny) return -1; return (typeof x==='string'? x.localeCompare(y,'ru') : x-y)*st.dir; });
  const thead=h('thead');
  if(cols.some(c=>c.group)){
    const g=h('tr',{class:'g'}); let i=0;
    while(i<cols.length){ const gr=cols[i].group||''; let span=1; while(i+span<cols.length && (cols[i+span].group||'')===gr) span++; g.appendChild(h('th',{colspan:span, class:(gr?'gs':'')+(i===0?' first':'') , text:gr})); i+=span; }
    thead.appendChild(g);
  }
  const hr=h('tr');
  cols.forEach(c=>{
    const on = c.key===st.key;
    const th=h('th',{class:[c.cls||'', c.first?'first':'', c.noSort?'':'sortable', c.gs?'gs':''].join(' '), title:c.title||null}, c.label, on? h('span',{class:'arr'},st.dir<0?' ↓':' ↑') : null);
    if(!c.noSort) th.addEventListener('click',()=>{ state.sorts[id] = {key:c.key, dir: on ? -st.dir : (c.cls==='l'?1:-1)}; render(); });
    hr.appendChild(th);
  });
  thead.appendChild(hr);
  const tb=h('tbody');
  const all = opts.total ? [...rows, opts.total] : rows;
  all.forEach((r,ri)=>{
    const tr=h('tr',{class: (r===opts.total?'tot':'')+(r._cls?' '+r._cls:'')});
    cols.forEach(c=>{
      let cell = c.cell ? c.cell(r) : r[c.key];
      if(!(cell instanceof HTMLElement && cell.tagName==='TD')) cell = h('td',{}, cell==null?'':cell);
      if(c.cls) cell.classList.add(...c.cls.split(' ').filter(Boolean));
      if(c.first) cell.classList.add('first');
      if(c.gs) cell.classList.add('gs');
      tr.appendChild(cell);
    });
    tb.appendChild(tr);
  });
  host.appendChild(h('div',{class:'tblwrap', style: opts.maxH?('max-height:'+opts.maxH):null}, h('table',{}, thead, tb)));
}

/* общий набор метрик для сущности (город / школа / направление / партнёр) */
function metricCols(D){
  const pmL = mShort(PM)+', '+baseLbl(D), lyL = mShort(LY)+', '+baseLbl(D);
  return [
    {key:'fact', label:'Факт 1-'+D, cell:r=>rub(r.fact), title:'Выручка с 1 по '+D+' число текущего месяца, ₽'},
    {key:'pmD', label:pmL, group:'К прошлому месяцу ('+mLabel(PM)+')', gs:true, cell:r=>rub(r.pmD)},
    {key:'dPM', label:'Δ ₽', group:'К прошлому месяцу ('+mLabel(PM)+')', cell:r=>deltaCell(r.dPM)},
    {key:'rPM', label:'Δ %', group:'К прошлому месяцу ('+mLabel(PM)+')', cell:r=>deltaCell(r.rPM,true)},
    {key:'flagPM', label:'Флаг', group:'К прошлому месяцу ('+mLabel(PM)+')', sort:r=>flagRank(r.flagPM), cell:r=>h('td',{},flagChip(r.flagPM))},
    {key:'lyD', label:lyL, group:'К прошлому году ('+mLabel(LY)+')', gs:true, cell:r=>rub(r.lyD)},
    {key:'dLY', label:'Δ ₽', group:'К прошлому году ('+mLabel(LY)+')', cell:r=>deltaCell(r.dLY)},
    {key:'rLY', label:'Δ %', group:'К прошлому году ('+mLabel(LY)+')', cell:r=>deltaCell(r.rLY,true)},
    {key:'flagLY', label:'Флаг', group:'К прошлому году ('+mLabel(LY)+')', sort:r=>flagRank(r.flagLY), cell:r=>h('td',{},flagChip(r.flagLY))},
    {key:'fcPM', label:'от '+mShort(PM), group:'Прогноз месяца, ₽', gs:true, cell:r=>rub(r.fcPM)},
    {key:'fcLY', label:'от '+mShort(LY), group:'Прогноз месяца, ₽', cell:r=>rub(r.fcLY)},
  ];
}
const flagRank = f => ({down:0,up:1,new:2,ok:3,small:4,none:5})[f];

function flagFilterPass(r){
  const f=state.flagFilter; if(f==='all') return true;
  if(f==='anyflag') return ['down','up'].includes(r.flagPM)||['down','up'].includes(r.flagLY);
  const [kind, per] = f.split('-'); return (per==='pm'?r.flagPM:r.flagLY)===kind;
}
function flagToolbar(host, extra){
  const sel=h('select',{onchange:e=>{state.flagFilter=e.target.value;render();}},
    ...[['all','Все'],['anyflag','Любой флаг'],['down-pm','Хуже к прошлому месяцу'],['up-pm','Лучше к прошлому месяцу'],['down-ly','Хуже к прошлому году'],['up-ly','Лучше к прошлому году']]
      .map(([v,l])=>h('option',{value:v,selected:state.flagFilter===v?'selected':null},l)));
  const thr=h('input',{type:'number',min:1,max:99,step:1,value:state.thr,onchange:e=>{state.thr=Math.max(1,+e.target.value||20);render();}});
  const mb=h('input',{type:'number',min:0,step:10000,value:state.minBase,onchange:e=>{state.minBase=Math.max(0,+e.target.value||0);render();}});
  host.appendChild(h('div',{class:'toolbar'},
    h('label',{},'Показать',sel),
    h('label',{},'Порог флага, ±%',thr),
    h('label',{},'Мин. база, ₽',mb),
    extra||null));
}
function flagSummary(host, rows, unit){
  const c=k=>rows.filter(r=>r.flagPM===k).length, cl=k=>rows.filter(r=>r.flagLY===k).length;
  const sum=(k,per)=>rows.filter(r=>(per==='pm'?r.flagPM:r.flagLY)===k).reduce((t,r)=>t+(per==='pm'?r.dPM:r.dLY),0);
  host.appendChild(h('div',{class:'summary'},
    h('div',{class:'pill'},h('b',{class:'neg'},String(c('down'))),unit+' хуже на '+state.thr+'%+ к '+mShort(PM)+' · '+mlnS(sum('down','pm'))),
    h('div',{class:'pill'},h('b',{class:'pos'},String(c('up'))),unit+' лучше на '+state.thr+'%+ к '+mShort(PM)+' · '+mlnS(sum('up','pm'))),
    h('div',{class:'pill'},h('b',{class:'neg'},String(cl('down'))),unit+' хуже на '+state.thr+'%+ к '+mShort(LY)+' · '+mlnS(sum('down','ly'))),
    h('div',{class:'pill'},h('b',{class:'pos'},String(cl('up'))),unit+' лучше на '+state.thr+'%+ к '+mShort(LY)+' · '+mlnS(sum('up','ly'))),
    h('div',{class:'pill'},h('b',{},String(rows.filter(r=>r.flagPM==='small').length)),'с малой базой (< '+rub(state.minBase)+' ₽) - без флага')
  ));
}
function contribBars(host, rows, per, title, n){
  n=n||8;
  const dk = per==='pm'?'dPM':'dLY', rk=per==='pm'?'rPM':'rLY';
  const down=rows.filter(r=>r[dk]<0).sort((a,b)=>a[dk]-b[dk]).slice(0,n);
  const up=rows.filter(r=>r[dk]>0).sort((a,b)=>b[dk]-a[dk]).slice(0,n);
  const max=Math.max(1,...down.map(r=>-r[dk]),...up.map(r=>r[dk]));
  const list=(arr,color)=>h('div',{class:'bars'},...arr.flatMap(r=>[
      h('div',{class:'nm',title:r.key,text:r.key}),
      h('div',{class:'bw'},h('div',{class:'b',style:`width:${(Math.abs(r[dk])/max*100*0.78).toFixed(1)}%;background:${color}`}),h('span',{class:'v',text:sRub(r[dk])+' ₽ ('+(ok(r[rk])?pct(r[rk]):'нет базы')+')'}))]));
  host.appendChild(h('div',{class:'card'},
    h('h3',{text:title}),
    h('p',{class:'sub',text:'Отклонение факта 1-'+state.cut+' от тех же чисел '+(per==='pm'?mGen(PM):mGen(LY))+', ₽. Сортировка по рублям: так видно, кто реально двигает итог сети.'}),
    h('div',{class:'two'},
      h('div',{},h('div',{class:'legend'},h('span',{},h('span',{class:'key-rect',style:'background:var(--down)'}),'Тянут вниз')), down.length?list(down,'var(--down)'):h('div',{class:'muted',text:'Нет'})),
      h('div',{},h('div',{class:'legend'},h('span',{},h('span',{class:'key-rect',style:'background:var(--up)'}),'Тянут вверх')), up.length?list(up,'var(--up)'):h('div',{class:'muted',text:'Нет'})))
  ));
}

/* ---------- контекст расчёта ---------- */
function ctx(){
  const D=state.cut, idx=selection(), stats=idx.map(i=>schoolStat(i,D)), tot=aggregate(stats);
  const cur=seriesSum(CUR,idx), pm=HAS_PM?seriesSum(PM,idx):null, ly=HAS_LY?seriesSum(LY,idx):null;
  const cmpPM=aggregate(stats.filter(x=>x.fact>0&&x.pmD>0)), cmpLY=aggregate(stats.filter(x=>x.fact>0&&x.lyD>0));
  return {D, idx, stats, tot, cur, pm, ly, cmpPM, cmpLY};
}
const scopeName = () => (state.scope==='own'?'Собственная сеть':'Вся сеть') + (state.country==='ru'?' · Россия':state.country==='foreign'?' · Зарубежье':'') + (state.dir!=='all'?' · '+state.dir:'');

/* ---------- вкладка: Обзор ---------- */
function tile(lbl, val, det, valCls, hero){ return h('div',{class:'tile'}, h('div',{class:'lbl',text:lbl}), h('div',{class:'val'+(hero?' hero':'')+(valCls?' '+valCls:''),text:val}), h('div',{class:'det'},...det.map(d=>h('div',{},d)))); }
function renderOverview(main, C){
  const {D,tot,cur,pm,ly,cmpPM,cmpLY}=C;
  const stale=M.stale||{}; const sm=[CUR,PM,LY].filter(m=>stale[m]);
  if(sm.length) main.appendChild(h('div',{class:'flash err',style:'margin-top:4px'},'Данные за '+sm.map(m=>mLabel(m).toLowerCase()+' - из выгрузки основного файла от '+new Date(stale[m]).toLocaleString('ru-RU',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'})).join('; ')+'. Архив этого месяца ещё не подключён, последний день может быть неполным. Как только архив появится на листе «Предыдущие месяца» или в настройках, отчёт пересчитается при следующем обновлении.'));
  main.appendChild(h('h2',{text:scopeName()+' · '+mLabel(CUR)}));
  main.appendChild(h('p',{class:'sub',text:(isFull(D) ? `Месяц завершён: факт за весь ${RU_M[ym(CUR)[1]-1]} сравнивается с полными месяцами - ${mLabel(PM).toLowerCase()} (${NPM} дн.) и ${mLabel(LY).toLowerCase()} (${NLY} дн.).` : `Факт по ${dmy(CUR,D)} включительно. Сравнение - с теми же числами ${RU_MG[ym(PM)[1]-1]} ${ym(PM)[0]} и ${RU_MG[ym(LY)[1]-1]} ${ym(LY)[0]}.`)+` Школ в выборке: ${tot.n}, с продажами в этом месяце: ${tot.act}.`}));
  const left = NCUR-D;
  main.appendChild(h('div',{class:'grid-tiles'},
    tile('Выручка 1-'+D+' '+RU_MG[ym(CUR)[1]-1], mln(tot.fact), [rub(tot.fact)+' ₽', 'за '+dm(CUR,D)+': '+rub(tot.yday)+' ₽'], null, true),
    tile('К '+mDat(PM)+' ('+baseLbl(D)+')', pct(tot.rPM), [h('span',{class:sgnCls(tot.dPM),text:ok(tot.dPM)?sRub(tot.dPM)+' ₽':'нет данных за базовый месяц'}), 'сопоставимые школы: '+pct(cmpPM.rPM)], sgnCls(tot.rPM)),
    tile('К '+mDat(LY)+' ('+baseLbl(D)+')', pct(tot.rLY), [h('span',{class:sgnCls(tot.dLY),text:ok(tot.dLY)?sRub(tot.dLY)+' ₽':'нет данных за базовый месяц'}), 'сопоставимые школы: '+pct(cmpLY.rLY)+' ('+cmpLY.n+' шк.)'], sgnCls(tot.rLY)),
    tile('Прогноз от '+mGen(PM), mln(tot.fcPM), ['итог '+mShort(PM)+': '+mln(tot.pmT), h('span',{class:sgnCls(tot.fcPM-tot.pmT),text:'к итогу: '+pct(tot.pmT>0?tot.fcPM/tot.pmT-1:null)})]),
    tile('Прогноз от '+mGen(LY), mln(tot.fcLY), ['итог '+mShort(LY)+': '+mln(tot.lyT), h('span',{class:sgnCls(tot.fcLY-tot.lyT),text:'к итогу: '+pct(tot.lyT>0?tot.fcLY/tot.lyT-1:null)})])
  ));

  // график накопленной выручки
  const curCum=cumArr(cur.daily), pmCum=pm?cumArr(pm.daily):[], lyCum=ly?cumArr(ly.daily):[];
  const xMax=Math.max(NCUR,NPM,NLY);
  const pad=(arr,n)=>{ const o=new Array(xMax).fill(null); for(let i=0;i<n&&i<arr.length;i++) o[i]=arr[i]; return o; };
  const factLine=pad(curCum,D);
  const fcLine=(refCum,refT)=>{ const o=new Array(xMax).fill(null); if(!(refT>0)) return o; const sD=refShare(refCum,refT,D); if(!(sD>0)) return o; for(let d=D; d<=NCUR; d++) o[d-1]=tot.fact*refShare(refCum,refT,d)/sD; return o; };
  const fPM=pm?fcLine(pmCum,tot.pmT):[], fLY=ly?fcLine(lyCum,tot.lyT):[];
  const card=h('div',{class:'card'});
  card.appendChild(h('h3',{text:'Накопленная выручка с начала месяца, ₽'}));
  card.appendChild(h('div',{class:'legend'},
    h('span',{},h('span',{class:'key-line',style:'border-color:var(--s1)'}),mLabel(CUR)+' - факт'),
    h('span',{},h('span',{class:'key-dash',style:'border-color:var(--s1)'}),'Прогноз (по форме базового месяца)'),
    h('span',{},h('span',{class:'key-line',style:'border-color:var(--s2)'}),mLabel(PM)),
    h('span',{},h('span',{class:'key-line',style:'border-color:var(--s3)'}),mLabel(LY))));
  const ch=h('div',{class:'chart'}); card.appendChild(ch); main.appendChild(card);
  lineChart(ch,{height:340, xMax, cut:D, cutLabel:'срез '+dmy(CUR,D).slice(0,5), aria:'Накопленная выручка по дням',
    series:[
      {label:mLabel(LY),color:'var(--s3)',data:pad(lyCum,NLY),endLabel:mShort(LY)+' итог'},
      {label:mLabel(PM),color:'var(--s2)',data:pad(pmCum,NPM),endLabel:mShort(PM)+' итог'},
      {label:'Прогноз от '+mShort(LY),color:'var(--s1)',data:fLY,dash:true,opacity:.75,endLabel:'прогноз от '+mShort(LY)},
      {label:'Прогноз от '+mShort(PM),color:'var(--s1)',data:fPM,dash:true,endLabel:'прогноз от '+mShort(PM)},
      {label:mLabel(CUR),color:'var(--s1)',data:factLine,width:2.5,endLabel:'факт'},
    ],
    tooltip:d=>({title:d+'-е число', rows:[
      {label:mShort(CUR)+(d<=D?' факт':' прогноз от '+mShort(PM)), color:'var(--s1)', dash:d>D, value:d<=D?rub(curCum[d-1]):(ok(fPM[d-1])?rub(fPM[d-1]):'-')},
      ...(d>D?[{label:mShort(CUR)+' прогноз от '+mShort(LY),color:'var(--s1)',dash:true,value:ok(fLY[d-1])?rub(fLY[d-1]):'-'}]:[]),
      {label:mShort(PM), color:'var(--s2)', value:d<=NPM?rub(pmCum[d-1]):'-'},
      {label:mShort(LY), color:'var(--s3)', value:d<=NLY?rub(lyCum[d-1]):'-'}]})
  });

  // прогноз: расчёт по шагам
  const fcBlock=(refName, refGen, refD, refT, refN, fc)=>{
    const k = refD>0 ? tot.fact/refD : null;
    const need = left>0 ? (refT-tot.fact)/left : null;
    const avgNow = tot.fact/D;
    return h('div',{class:'card'},
      h('h3',{text:'Прогноз от '+refGen}),
      h('div',{class:'calc'},
        h('div',{text:'1. Факт 1-'+D+' '+RU_MG[ym(CUR)[1]-1]}), h('div',{text:rub(tot.fact)+' ₽'}),
        h('div',{text:'2. '+refName+', '+baseLbl(D)}), h('div',{text:rub(refD)+' ₽'}),
        h('div',{text:'3. Темп = (1) / (2)'}), h('div',{text:ok(k)?k.toFixed(4).replace('.',','):'н/д'}),
        h('div',{text:'4. '+refName+' - весь месяц ('+refN+' дн.)'}), h('div',{text:rub(refT)+' ₽'}),
        h('div',{class:'res',text:'Прогноз = (4) × (3)'}), h('div',{class:'res',text:rub(fc)+' ₽'})),
      h('div',{class:'note'},
        left>0 ? 'Осталось дней: '+left+'. Чтобы повторить итог базы ('+mln(refT)+'), нужно в среднем '+(ok(need)?rub(need):'н/д')+' ₽ в день; сейчас в среднем '+rub(avgNow)+' ₽ в день. ' : 'Месяц завершён: прогноз равен факту. ',
        left>0 ? 'Простой расчёт «среднее в день × '+NCUR+'» дал бы '+mln(avgNow*NCUR)+' - он не учитывает рост продаж к концу месяца.' : ''));
  };
  main.appendChild(h('h2',{text:'Прогноз финальной выручки'}));
  main.appendChild(h('p',{class:'sub',text:'Логика: текущий месяц идёт с тем же темпом относительно базы, что и за прошедшие дни; форма накопления (рост к концу месяца) берётся из базового месяца.'}));
  const gap = (ok(tot.fcPM)&&ok(tot.fcLY)) ? tot.fcPM-tot.fcLY : null;
  main.appendChild(h('div',{class:'two'}, fcBlock(mLabel(PM), mGen(PM), tot.pmD, tot.pmT, NPM, tot.fcPM), fcBlock(mLabel(LY), mGen(LY), tot.lyD, tot.lyT, NLY, tot.fcLY)));
  if(ok(gap)) main.appendChild(h('p',{class:'note',text:'Разница между двумя прогнозами: '+sRub(gap)+' ₽ ('+pct(tot.fcLY>0?tot.fcPM/tot.fcLY-1:null)+'). Чем она больше, тем сильнее форма месяца в прошлом году отличается от прошлого месяца - и тем меньше доверия точечной цифре.'}));

  // разрез: собственная сеть по филиалам / вклад городов
  if(state.scope==='own'){
    main.appendChild(h('h2',{text:'Филиалы Собственной сети'}));
    const noLY=C.stats.filter(x=>x.lyD<=0).map(x=>S[x.i].n);
    main.appendChild(h('p',{class:'sub',text:'Собственная сеть - школы партнёра «'+M.ownPartner+'». Сопоставимые для сравнения с прошлым годом - филиалы с продажами в обоих периодах.'+(noLY.length?' Без продаж в '+mPrep(LY)+' ('+baseLbl(D)+'): '+noLY.join(', ')+'.':'')}));
    const rows=C.stats.map(x=>Object.assign(derive(Object.assign({},x,{n:1,act:x.fact>0?1:0})),{key:S[x.i].n}));
    const cols=[{key:'key',label:'Филиал',cls:'l',first:true,cell:r=>h('td',{},r.key)},
      {key:'yday',label:'Вчера, ₽',cell:r=>rub(r.yday),title:'Продажи за '+dmy(CUR,D)}, ...metricCols(D)];
    dataTable(main,'own',cols,rows,{defaultSort:{key:'fact',dir:-1}, total:Object.assign({},tot,{key:'Итого'}), maxH:'none'});
  } else {
    main.appendChild(h('h2',{text:'Что двигает отклонение'}));
    const cities=groupBy(C.stats, sc=>sc.c);
    contribBars(main, cities, 'pm', 'Города: вклад в отклонение к '+mDat(PM), 6);
  }
}

/* ---------- вкладка: По дням ---------- */
function renderDaily(main, C){
  const {D,tot,cur,pm,ly}=C;
  main.appendChild(h('h2',{text:'Продажи по дням · '+scopeName()}));
  main.appendChild(h('p',{class:'sub',text:'Продажи дня и накопленный итог с начала месяца; сравнение с тем же числом прошлого месяца и прошлого года. Строка среза выделена. Будущие дни показывают только базу.'}));
  const card=h('div',{class:'card'});
  card.appendChild(h('h3',{text:'Продажи дня, ₽'}));
  card.appendChild(h('div',{class:'legend'},
    h('span',{},h('span',{class:'key-rect',style:'background:var(--s1)'}),mLabel(CUR)),
    h('span',{},h('span',{class:'key-dot',style:'background:var(--s2)'}),mLabel(PM)),
    h('span',{},h('span',{class:'key-dot',style:'background:var(--s3)'}),mLabel(LY)),
    h('span',{},h('span',{class:'key-rect',style:'background:var(--surface-2);border:1px solid var(--border)'}),'выходные (текущий месяц)')));
  const ch=h('div',{class:'chart'}); card.appendChild(ch); main.appendChild(card);
  const partial = (M.todayInCur && D===AS_OF && TODAY<=NCUR && cur.len>=TODAY) ? TODAY : null;
  const bars=cur.daily.map((v,k)=>k<D||(partial&&k===partial-1)?v:null);
  dailyChart(ch,{xMax:NCUR, bars, partial, weekend:d=>isWeekend(CUR,d), aria:'Продажи по дням',
    dots:[{color:'var(--s2)',data:pm?pm.daily:[]},{color:'var(--s3)',data:ly?ly.daily:[]}],
    tooltip:d=>({title:dm(CUR,d)+', '+wd(CUR,d)+(partial===d?' (день идёт)':''), rows:[
      {label:mShort(CUR),color:'var(--s1)',value:ok(bars[d-1])?rub(bars[d-1]):'-'},
      {label:mShort(PM)+' ('+wd(PM,d)+')',color:'var(--s2)',value:pm&&d<=NPM?rub(pm.daily[d-1]):'-'},
      {label:mShort(LY)+' ('+wd(LY,d)+')',color:'var(--s3)',value:ly&&d<=NLY?rub(ly.daily[d-1]):'-'}]})});

  const curCum=cumArr(cur.daily), pmCum=pm?cumArr(pm.daily):[], lyCum=ly?cumArr(ly.daily):[];
  const rows=[];
  for(let d=1; d<=NCUR; d++){
    const fact = d<=D, isToday = partial===d;
    const r={d, _cls: d===D?'cut':isToday?'today':d>D?'fut':''};
    r.cd = fact||isToday ? cur.daily[d-1] : null; r.cc = fact ? curCum[d-1] : null;
    r.pd = pm&&d<=NPM ? pm.daily[d-1] : null; r.pc = pm&&d<=NPM ? pmCum[d-1] : null;
    r.ld = ly&&d<=NLY ? ly.daily[d-1] : null; r.lc = ly&&d<=NLY ? lyCum[d-1] : null;
    const dd=(a,b)=>fact&&ok(a)&&ok(b)?a-b:null, rr=(a,b)=>fact&&ok(a)&&b>0?a/b-1:null;
    Object.assign(r,{pdd:dd(r.cd,r.pd),pdr:rr(r.cd,r.pd),pcd:dd(r.cc,r.pc),pcr:rr(r.cc,r.pc),ldd:dd(r.cd,r.ld),ldr:rr(r.cd,r.ld),lcd:dd(r.cc,r.lc),lcr:rr(r.cc,r.lc)});
    rows.push(r);
  }
  const G1=mLabel(CUR), G2='Прошлый месяц: '+mLabel(PM), G3='Прошлый год: '+mLabel(LY);
  const n=v=>ok(v)?rub(v):'';
  const cols=[
    {key:'d',label:'Дата',cls:'l',first:true,noSort:true,cell:r=>h('td',{}, dmy(CUR,r.d).slice(0,5)+' ', h('span',{class:'muted',text:wd(CUR,r.d)}), r.d===D?h('span',{class:'chip own',style:'margin-left:6px',text:'срез'}):null, r._cls==='today'?h('span',{class:'chip small',style:'margin-left:6px',text:'день идёт'}):null)},
    {key:'cd',label:'Продажи дня',group:G1,noSort:true,gs:true,cell:r=>n(r.cd)},
    {key:'cc',label:'Накоплено',group:G1,noSort:true,cell:r=>h('td',{style:'font-weight:600'},n(r.cc))},
    {key:'pd',label:'Продажи дня',group:G2,noSort:true,gs:true,cell:r=>n(r.pd)},
    {key:'pdd',label:'Δ дня ₽',group:G2,noSort:true,cell:r=>ok(r.pdd)?deltaCell(r.pdd):''},
    {key:'pdr',label:'Δ дня %',group:G2,noSort:true,cell:r=>ok(r.pdr)?deltaCell(r.pdr,true):''},
    {key:'pc',label:'Накоплено',group:G2,noSort:true,cell:r=>n(r.pc)},
    {key:'pcd',label:'Δ накоп. ₽',group:G2,noSort:true,cell:r=>ok(r.pcd)?deltaCell(r.pcd):''},
    {key:'pcr',label:'Δ накоп. %',group:G2,noSort:true,cell:r=>ok(r.pcr)?deltaCell(r.pcr,true):''},
    {key:'ld',label:'Продажи дня',group:G3,noSort:true,gs:true,cell:r=>n(r.ld)},
    {key:'ldd',label:'Δ дня ₽',group:G3,noSort:true,cell:r=>ok(r.ldd)?deltaCell(r.ldd):''},
    {key:'ldr',label:'Δ дня %',group:G3,noSort:true,cell:r=>ok(r.ldr)?deltaCell(r.ldr,true):''},
    {key:'lc',label:'Накоплено',group:G3,noSort:true,cell:r=>n(r.lc)},
    {key:'lcd',label:'Δ накоп. ₽',group:G3,noSort:true,cell:r=>ok(r.lcd)?deltaCell(r.lcd):''},
    {key:'lcr',label:'Δ накоп. %',group:G3,noSort:true,cell:r=>ok(r.lcr)?deltaCell(r.lcr,true):''},
  ];
  const total={d:0,_cls:'',cd:null,cc:null};
  const T = h('div');
  // строка «итог месяца / прогноз»
  dataTable(T,'daily',cols,rows,{defaultSort:{key:'d',dir:1}, maxH:'none',
    total:{d:'tot', cd:null, cc:null, pd:pm?totA(pm.daily):null, pc:null, ld:ly?totA(ly.daily):null, lc:null}});
  // переопределим подпись итоговой строки
  const last=T.querySelector('tr.tot');
  if(last){
    const tds=last.querySelectorAll('td');
    tds[0].textContent='Итог месяца';
    tds[1].textContent=''; tds[2].textContent='';
    tds[2].appendChild(h('div',{style:'font-weight:400;font-size:11.5px;color:var(--ink-2)'},'прогноз от '+mShort(PM)+': '+rub(tot.fcPM)));
    tds[2].appendChild(h('div',{style:'font-weight:400;font-size:11.5px;color:var(--ink-2)'},'прогноз от '+mShort(LY)+': '+rub(tot.fcLY)));
    tds[6].textContent=pm?rub(totA(pm.daily)):''; tds[3].textContent=''; tds[12].textContent=ly?rub(totA(ly.daily)):''; tds[9].textContent='';
  }
  main.appendChild(T);
  if(NPM>NCUR) main.appendChild(h('p',{class:'note',text:`В ${mPrep(PM)} ${NPM} дн., в текущем месяце ${NCUR}. Продажи ${NPM}-го числа (${pm?rub(pm.daily[NPM-1]):'н/д'} ₽) входят в итог прошлого месяца и в прогноз, но в построчном сравнении по числам не участвуют.`}));
}

/* ---------- вкладка: Города ---------- */
function renderCities(main, C){
  main.appendChild(h('h2',{text:'Города · '+scopeName()}));
  main.appendChild(h('p',{class:'sub',text:'Факт 1-'+C.D+' против тех же чисел прошлого месяца и прошлого года. Флаг ставится при отклонении ±'+state.thr+'% и более, если база не меньше '+rub(state.minBase)+' ₽.'}));
  const rows=groupBy(C.stats, sc=>sc.c).map(r=>Object.assign(r,{co:S[r.items[0].i].co, share: C.tot.fact>0? r.fact/C.tot.fact : null}));
  flagToolbar(main);
  flagSummary(main, rows, 'городов');
  contribBars(main, rows, 'pm', 'Вклад городов в отклонение к '+mDat(PM), 8);
  contribBars(main, rows, 'ly', 'Вклад городов в отклонение к '+mDat(LY), 8);
  const cols=[{key:'key',label:'Город',cls:'l',first:true,cell:r=>h('td',{},r.key)},
    {key:'co',label:'Страна',cls:'l',cell:r=>h('td',{},r.co)},
    {key:'act',label:'Школ с продажами',title:'Школ с продажами в текущем месяце / всего школ в выборке',cell:r=>r.act+' / '+r.n},
    ...metricCols(C.D),
    {key:'share',label:'Доля в факте',gs:true,cell:r=>ok(r.share)?(r.share*100).toFixed(1).replace('.',',')+'%':''}];
  dataTable(main,'cities',cols,rows.filter(flagFilterPass),{defaultSort:{key:'fact',dir:-1}, total:Object.assign({},C.tot,{key:'Итого',co:'',share:1})});
}

/* ---------- вкладка: Школы ---------- */
function renderSchools(main, C){
  main.appendChild(h('h2',{text:'Школы · '+scopeName()}));
  main.appendChild(h('p',{class:'sub',text:'Уровень филиала (столбец «Школа»). Здесь же - «тишина» (сколько дней подряд до среза без продаж) и признак новой школы (нет в данных за '+mLabel(LY).toLowerCase()+').'}));
  let rows=C.stats.map(x=>{ const sc=S[x.i]; return Object.assign(derive(Object.assign({},x,{n:1,act:x.fact>0?1:0})),{key:sc.n, c:sc.c, d:sc.d, p:sc.p, cl:sc.cl, own:sc.own, isNew:!x.inLY}); });
  const silentSel=h('input',{type:'number',min:1,max:31,value:state.silentN,onchange:e=>{state.silentN=Math.max(1,+e.target.value||5);render();}});
  const search=h('input',{type:'search',placeholder:'Поиск школы / города / партнёра',value:state.search,style:'width:260px',oninput:e=>{state.search=e.target.value;clearTimeout(window.__st);window.__st=setTimeout(()=>{render();const el=document.querySelector('input[type=search]');if(el){el.focus();el.setSelectionRange(el.value.length,el.value.length);}},250);}});
  const act=h('input',{type:'checkbox',checked:state.onlyActive?'checked':null,onchange:e=>{state.onlyActive=e.target.checked;render();}});
  const silentOnly=h('select',{onchange:e=>{state.schoolMode=e.target.value;render();}},
    ...[['all','все школы'],['silent','только «тишина» ≥ N дней'],['new','только новые'],['closed','только с отметкой «Закрыта»']].map(([v,l])=>h('option',{value:v,selected:(state.schoolMode||'all')===v?'selected':null},l)));
  flagToolbar(main, [h('label',{},'Тишина, N дней',silentSel), h('label',{},'Режим',silentOnly), h('label',{},act,'скрыть закрытые без продаж'), search]);
  const q=state.search.trim().toLowerCase();
  if(state.onlyActive) rows=rows.filter(r=>!(r.cl && r.fact===0 && r.pmD===0));
  if(q) rows=rows.filter(r=>(r.key+' '+r.c+' '+r.p).toLowerCase().includes(q));
  const mode=state.schoolMode||'all';
  if(mode==='silent') rows=rows.filter(r=>!r.cl && r.silent>=state.silentN && r.pmT>0);
  if(mode==='new') rows=rows.filter(r=>r.isNew);
  if(mode==='closed') rows=rows.filter(r=>r.cl);
  flagSummary(main, rows, 'школ');
  const cols=[{key:'key',label:'Школа',cls:'l',first:true,cell:r=>h('td',{}, r.key, r.own?h('span',{class:'chip own',style:'margin-left:6px',text:'собств.'}):null)},
    {key:'c',label:'Город',cls:'l',cell:r=>h('td',{},r.c)},
    {key:'d',label:'Напр.',cls:'l',cell:r=>h('td',{},r.d)},
    {key:'p',label:'Партнёр',cls:'l',cell:r=>h('td',{},h('span',{class:'trunc',title:r.p,text:r.p}))},
    {key:'cl',label:'Статус',cls:'l',sort:r=>(r.cl?2:0)+(r.isNew?1:0),cell:r=>h('td',{}, r.cl?h('span',{class:'chip closed',text:'Закрыта'}):null, r.isNew?h('span',{class:'chip new',style:'margin-left:4px',text:'новая'}):null)},
    {key:'silent',label:'Дней без продаж',title:'Подряд до даты среза включительно',cell:r=>h('td',{class: !r.cl&&r.silent>=state.silentN&&r.pmT>0?'neg':''}, String(r.silent))},
    ...metricCols(C.D)];
  dataTable(main,'schools',cols,rows.filter(flagFilterPass),{defaultSort:{key:'fact',dir:-1}});
}

/* ---------- вкладка: Направления и страны ---------- */
function renderDirs(main, C){
  main.appendChild(h('h2',{text:'Направления · '+scopeName()}));
  main.appendChild(h('p',{class:'sub',text:'Барабны, Вокал, Гитара, Кидс - по столбцу «Направление».'}));
  const share=r=>C.tot.fact>0?r.fact/C.tot.fact:null;
  const shareCol={key:'share',label:'Доля в факте',gs:true,cell:r=>ok(r.share)?(r.share*100).toFixed(1).replace('.',',')+'%':''};
  const dirs=groupBy(C.stats, sc=>sc.d).map(r=>Object.assign(r,{share:share(r)}));
  dataTable(main,'dirs',[{key:'key',label:'Направление',cls:'l',first:true,cell:r=>h('td',{},r.key)},{key:'act',label:'Школ с продажами',cell:r=>r.act+' / '+r.n},...metricCols(C.D),shareCol],dirs,{defaultSort:{key:'fact',dir:-1},total:Object.assign({},C.tot,{key:'Итого',share:1}),maxH:'none'});
  main.appendChild(h('h2',{text:'Россия и зарубежье'}));
  const rz=groupBy(C.stats, sc=>sc.co==='Россия'?'Россия':'Зарубежье').map(r=>Object.assign(r,{share:share(r)}));
  dataTable(main,'rz',[{key:'key',label:'Регион',cls:'l',first:true,cell:r=>h('td',{},r.key)},{key:'act',label:'Школ с продажами',cell:r=>r.act+' / '+r.n},...metricCols(C.D),shareCol],rz,{defaultSort:{key:'fact',dir:-1},maxH:'none'});
  main.appendChild(h('h2',{text:'Страны'}));
  const co=groupBy(C.stats, sc=>sc.co).map(r=>Object.assign(r,{share:share(r)}));
  dataTable(main,'countries',[{key:'key',label:'Страна',cls:'l',first:true,cell:r=>h('td',{},r.key)},{key:'act',label:'Школ с продажами',cell:r=>r.act+' / '+r.n},...metricCols(C.D),shareCol],co,{defaultSort:{key:'fact',dir:-1},maxH:'none'});
}

/* ---------- вкладка: Партнёры ---------- */
function renderPartners(main, C){
  main.appendChild(h('h2',{text:'Партнёры · '+scopeName()}));
  main.appendChild(h('p',{class:'sub',text:'Выручка по портфелю школ партнёра (столбец «Партнёр»). «УК» - филиалы Собственной сети.'}));
  const rows=groupBy(C.stats, sc=>sc.p||'(не указан)').map(r=>Object.assign(r,{share:C.tot.fact>0?r.fact/C.tot.fact:null}));
  flagToolbar(main);
  flagSummary(main, rows, 'партнёров');
  const cols=[{key:'key',label:'Партнёр',cls:'l',first:true,cell:r=>h('td',{},h('span',{class:'trunc',title:r.key,text:r.key}))},
    {key:'act',label:'Школ с продажами',cell:r=>r.act+' / '+r.n},...metricCols(C.D),
    {key:'share',label:'Доля в факте',gs:true,cell:r=>ok(r.share)?(r.share*100).toFixed(1).replace('.',',')+'%':''}];
  dataTable(main,'partners',cols,rows.filter(flagFilterPass),{defaultSort:{key:'fact',dir:-1},total:Object.assign({},C.tot,{key:'Итого',share:1})});
}

/* ---------- вкладка: Динамика 12 мес. ---------- */
function renderTrend(main, C){
  main.appendChild(h('h2',{text:'Выручка по месяцам · '+scopeName()}));
  main.appendChild(h('p',{class:'sub',text:'Итог месяца по архивным файлам. Текущий месяц: факт на дату среза и диапазон двух прогнозов.'}));
  const [cy,cm]=ym(CUR); const months=[];
  for(let k=12;k>=0;k--){ const d=new Date(cy,cm-1-k,1); months.push(d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')); }
  const items=[]; const rows=[];
  let prevVal=null;
  months.forEach(m=>{
    const arr=DATA.monthly[m]; const isCur=m===CUR;
    if(!arr){ items.push({label:mShort(m),missing:true,title:mLabel(m),tip:[{label:'Нет архивного файла',value:''}]}); rows.push({m,missing:true}); prevVal=null; return; }
    let v=0, act=0; for(const i of C.idx){ const x=arr[i]; if(x){ v+=x; if(x>0) act++; } }
    if(isCur){ v=C.tot.fact; act=C.tot.act; }
    const lo=isCur?Math.min(C.tot.fcPM,C.tot.fcLY):null, hi=isCur?Math.max(C.tot.fcPM,C.tot.fcLY):null;
    const lyM=(+m.slice(0,4)-1)+m.slice(4); const lyArr=DATA.monthly[lyM];
    let lyV=null; if(lyArr){ lyV=0; for(const i of C.idx){ if(lyArr[i]) lyV+=lyArr[i]; } }
    items.push({label:mShort(m), value:v, current:isCur, fcLo:ok(lo)?lo:null, fcHi:ok(hi)?hi:null,
      label2: isCur? (ok(hi)?(hi/1e6).toFixed(0)+'-'+(lo/1e6).toFixed(0):'') : (v/1e6).toFixed(0),
      title: mLabel(m)+(isCur?' (идёт)':''),
      tip: isCur ? [{label:'Факт 1-'+C.D,value:rub(v)},{label:'Прогноз от '+mShort(PM),value:rub(C.tot.fcPM)},{label:'Прогноз от '+mShort(LY),value:rub(C.tot.fcLY)}] : [{label:'Выручка, ₽',value:rub(v)},{label:'Школ с продажами',value:String(act)}]});
    rows.push({m, v, act, perSchool: (!isCur && act)? v/act : null, mom: (!isCur && prevVal>0)? v/prevVal-1 : null, yoy: (!isCur && lyV>0)? v/lyV-1 : null, isCur, fcPM:isCur?C.tot.fcPM:null, fcLY:isCur?C.tot.fcLY:null});
    prevVal = isCur? null : v;
  });
  const card=h('div',{class:'card'});
  card.appendChild(h('div',{class:'legend'},
    h('span',{},h('span',{class:'key-rect',style:'background:var(--s1)'}),'Выручка за месяц (текущий - факт на дату среза)'),
    h('span',{},h('span',{class:'key-rect',style:'background:var(--s1-light)'}),'Диапазон прогноза текущего месяца'),
    h('span',{},h('span',{class:'key-line',style:'border-color:var(--ink-2);width:14px'}),'нижний прогноз')));
  const ch=h('div',{class:'chart'}); card.appendChild(ch); main.appendChild(card);
  monthChart(ch, items);
  const cols=[
    {key:'m',label:'Месяц',cls:'l',first:true,noSort:true,cell:r=>h('td',{},mLabel(r.m), r.isCur?h('span',{class:'chip small',style:'margin-left:6px',text:'1-'+C.D+', идёт'}):null)},
    {key:'v',label:'Выручка, ₽',noSort:true,cell:r=>r.missing?h('td',{class:'muted'},'нет файла'):rub(r.v)},
    {key:'mom',label:'Δ к пред. месяцу',noSort:true,cell:r=>ok(r.mom)?deltaCell(r.mom,true):''},
    {key:'yoy',label:'Δ к году назад',noSort:true,cell:r=>ok(r.yoy)?deltaCell(r.yoy,true):''},
    {key:'act',label:'Школ с продажами',noSort:true,cell:r=>r.missing?'':String(r.act)},
    {key:'ps',label:'Выручка на школу с продажами, ₽',noSort:true,cell:r=>ok(r.perSchool)?rub(r.perSchool):''},
    {key:'fc',label:'Прогноз (от ПМ / от ПГ), ₽',noSort:true,cell:r=>r.isCur?rub(r.fcPM)+' / '+rub(r.fcLY):''}];
  dataTable(main,'trend',cols,rows.reverse(),{defaultSort:{key:'m',dir:1},maxH:'none'});
  const miss=months.filter(m=>!DATA.monthly[m]);
  if(miss.length) main.appendChild(h('p',{class:'note',text:'Нет данных за: '+miss.map(mLabel).join(', ')+'. Добавьте ссылку на архив в настройках или на лист «Предыдущие месяца» - месяц подключится при следующем обновлении.'}));
}

/* ---------- вкладка: Методика ---------- */
function renderMethod(main, C){
  const t=C.tot, q=DATA.quality||{};
  const box=h('div',{class:'method'});
  const P=(...k)=>box.appendChild(h('p',{},...k));
  const list=(items)=>{ const ul=h('ul'); items.forEach(x=>ul.appendChild(h('li',{text:x}))); box.appendChild(ul); };
  box.appendChild(h('h2',{text:'Определения'}));
  list([
    'Выручка - столбец H «Выручка (рубли)» как есть, без вычета возвратов.',
    'Филиал - столбец K «Школа». Дубли строк «школа + дата» суммируются.',
    'Факт - сумма выручки с 1-го числа по дату среза включительно. Дата среза - вчера (D-1). Сегодняшний день показывается серым и в расчётах не участвует.',
    'Сравнение - по числу месяца: 1-D текущего месяца против 1-D прошлого месяца и 1-D того же месяца прошлого года. Когда месяц завершён (срез - последний день, например 1-го числа следующего месяца), база - полные месяцы целиком, включая 31-е число.',
    'Сопоставимые школы - школы с продажами и в текущем периоде (1-D), и в базовом (1-D). Показываются рядом с итогом, чтобы отделить эффект открытий и закрытий.',
    'Флаг - отклонение ±'+state.thr+'% и более к базе за 1-D; если база меньше '+rub(state.minBase)+' ₽, флаг не ставится («мала база»). Если в базе продаж не было - «нет базы».',
    'Собственная сеть - школы, у которых в столбце «Партнер» указано «'+M.ownPartner+'» (по последнему месяцу, где школа есть в данных). Сейчас: '+(M.own.length?M.own.join(', '):'нет')+'.',
    'Атрибуты школы (город, направление, партнёр, отметка «Закрыта») берутся из самого свежего месяца.'
  ]);
  box.appendChild(h('h2',{text:'Формулы прогноза'}));
  P('Прогноз от базы = Итог базового месяца × (Факт 1-D / База 1-D).');
  P('Это то же самое, что «Факт 1-D / доля месяца, которую база набрала к дню D». На текущей выборке: доля '+mGen(PM)+' к '+C.D+'-му числу = '+(t.pmT>0?(t.pmD/t.pmT*100).toFixed(1).replace('.',','):'н/д')+'%, доля '+mGen(LY)+' = '+(t.lyT>0?(t.lyD/t.lyT*100).toFixed(1).replace('.',','):'н/д')+'%.');
  P('Месяцы разной длины: построчно сравниваются одинаковые числа; в итог базы входят все её дни (например, 31-е число). Линия прогноза на графике в последний день текущего месяца выходит ровно на прогнозную сумму.');
  box.appendChild(h('h2',{text:'Состояние данных'}));
  const gen=new Date(M.generatedAt);
  const items=['Обновлено: '+gen.toLocaleString('ru-RU')+'. Месяцы в наборе: '+M.months.map(mLabel).join(', ')+'.'];
  const dupNames=[...new Set((q.dups||[]).flatMap(x=>x.schools))];
  items.push('Дубли «школа + дата»: '+(dupNames.length?dupNames.join(', ')+' - суммируются':'нет')+'.');
  items.push('Отметка «Закрыта» встречается в разных написаниях - любая непустая отметка считается закрытием.');
  items.push('Названия городов: убираются лишние пробелы; суффикс страны («Астана, Казахстан») отбрасывается.');
  (q.warnings||[]).forEach(w=>items.push('Предупреждение: '+w));
  list(items);
  main.appendChild(box);
}

/* ---------- каркас ---------- */
function render(){
  const main=document.getElementById('main'); const y=scrollY;
  main.textContent='';
  document.querySelectorAll('#scope button').forEach(b=>b.classList.toggle('on',b.dataset.v===state.scope));
  document.querySelectorAll('#tabs button').forEach(b=>b.classList.toggle('on',b.dataset.v===state.tab));
  const C=ctx();
  const as=document.getElementById('asof'); as.textContent='';
  as.append('Данные по ', h('b',{text:dmy(CUR,state.cut)}), ' включительно', state.cut===AS_OF?(M.todayInCur?' · '+dmy(CUR,TODAY).slice(0,5)+' - день идёт, в расчёт не входит':''):' · срез в прошлое');
  ({overview:renderOverview, daily:renderDaily, cities:renderCities, schools:renderSchools, dirs:renderDirs, partners:renderPartners, trend:renderTrend, method:renderMethod}[state.tab]||renderOverview)(main,C);
  scrollTo(0,y);
}
function init(){
  const dirs=[...new Set(S.map(x=>x.d))].filter(Boolean).sort();
  const fd=document.getElementById('f-dir'); dirs.forEach(d=>fd.appendChild(h('option',{value:d},d)));
  const fc=document.getElementById('f-cut'); for(let d=AS_OF; d>=1; d--) fc.appendChild(h('option',{value:d}, dmy(CUR,d)+(d===AS_OF?(M.asOfShifted?' (последний в данных)':' (вчера)'):'')));
  fc.addEventListener('change',e=>{state.cut=+e.target.value;render();});
  fd.addEventListener('change',e=>{state.dir=e.target.value;render();});
  document.getElementById('f-country').addEventListener('change',e=>{state.country=e.target.value;render();});
  document.querySelectorAll('#scope button').forEach(b=>b.addEventListener('click',()=>{state.scope=b.dataset.v;store.set('scope',state.scope);render();}));
  document.querySelectorAll('#tabs button').forEach(b=>b.addEventListener('click',()=>{state.tab=b.dataset.v;store.set('tab',state.tab);render();scrollTo(0,0);}));
  document.getElementById('theme').addEventListener('click',()=>{
    const r=document.documentElement; const dark = r.dataset.theme ? r.dataset.theme==='dark' : matchMedia('(prefers-color-scheme: dark)').matches;
    r.dataset.theme = dark?'light':'dark'; store.set('theme', r.dataset.theme); render(); });
  const th=store.get('theme',null); if(th) document.documentElement.dataset.theme=th;
  let rt; addEventListener('resize',()=>{clearTimeout(rt);rt=setTimeout(render,150);});
  render();
}
init();
