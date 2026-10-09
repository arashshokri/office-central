(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root.document) root.document.addEventListener('DOMContentLoaded', () => api.mount(root.document));
})(typeof window === 'object' ? window : globalThis, function () {
    'use strict';
    const dayMS = 86400000;
    const months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
    const formatter = new Intl.DateTimeFormat('en-u-ca-persian-nu-latn', {timeZone:'UTC', year:'numeric', month:'numeric', day:'numeric'});
    const digits = value => String(value).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]);
    function parts(date) {
        const p = Object.fromEntries(formatter.formatToParts(date).map(x => [x.type, x.value]));
        return {year:Number(p.year), month:Number(p.month), day:Number(p.day)};
    }
    const iso = date => date.toISOString().slice(0,10);
    function parse(value) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return null;
        const date = new Date(value+'T00:00:00Z');
        return Number.isFinite(date.getTime()) && iso(date) === value ? date : null;
    }
    const years = new Map();
    function yearStart(year) {
        if (!Number.isInteger(year) || year < 1200 || year > 1601) throw new Error('Unsupported Jalali year');
        if (!years.has(year)) {
            let date = new Date(Date.UTC(year+621, 2, 1));
            for (let i=0;i<62;i++,date=new Date(date.getTime()+dayMS)) {
                const p=parts(date);
                if (p.year===year && p.month===1 && p.day===1) { years.set(year,date.getTime()); break; }
            }
        }
        if (!years.has(year)) throw new Error('Jalali calendar is unavailable');
        return years.get(year);
    }
    function monthDays(year, month) {
        const offset = month<=6 ? (month-1)*31 : 186+(month-7)*30;
        const first = yearStart(year)+offset*dayMS;
        const count = month<=6 ? 31 : month<12 ? 30 : (yearStart(year+1)-yearStart(year))/dayMS-336;
        return Array.from({length:count},(_,i)=>({date:iso(new Date(first+i*dayMS)),day:i+1,weekday:(new Date(first+i*dayMS).getUTCDay()+1)%7}));
    }
    function today() {
        const p=Object.fromEntries(new Intl.DateTimeFormat('en-CA', {timeZone:'Asia/Tehran',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date()).map(x=>[x.type,x.value]));
        return `${p.year}-${p.month}-${p.day}`;
    }
    function display(value) {
        const date=parse(value); if (!date) return '';
        const p=parts(date); return digits(`${p.year}/${String(p.month).padStart(2,'0')}/${String(p.day).padStart(2,'0')}`);
    }
    function mount(document) {
        document.querySelectorAll('[data-jalali-picker]').forEach(field=>{
            const get = selector=>field.querySelector(selector);
            const input=get('[data-date-value]'), trigger=get('[data-date-open]'), text=get('[data-date-text]'), popup=get('[data-date-popup]'), grid=get('[data-date-days]');
            const monthSelect=get('[data-date-month]'), yearSelect=get('[data-date-year]'), empty=field.dataset.dateEmpty;
            const option=(select,value,label)=>{const o=document.createElement('option');o.value=value;o.textContent=label;select.append(o);};
            months.forEach((name,index)=>option(monthSelect,index+1,name));
            for(let y=1200;y<=1600;y++) option(yearSelect,y,digits(y));
            let current=parts(parse(input.value)||parse(today()));
            const close=()=>{popup.hidden=true;trigger.setAttribute('aria-expanded','false');};
            const refresh=()=>{text.textContent=display(input.value)||empty;};
            const choose=value=>{input.value=value;input.dispatchEvent(new Event('change',{bubbles:true}));refresh();close();trigger.focus();};
            function draw(focus=false) {
                monthSelect.value=current.month;yearSelect.value=current.year;grid.replaceChildren();
                const days=monthDays(current.year,current.month);
                for(let i=0;i<days[0].weekday;i++){const blank=document.createElement('span');blank.setAttribute('aria-hidden','true');grid.append(blank);}
                days.forEach(item=>{const button=document.createElement('button');button.type='button';button.textContent=digits(item.day);button.dataset.isoDate=item.date;
                    button.setAttribute('aria-label',display(item.date));button.setAttribute('aria-pressed',String(input.value===item.date));
                    if(item.date===today()) button.setAttribute('aria-current','date');
                    button.addEventListener('click',()=>choose(item.date));grid.append(button);
                });
                get('[data-date-prev]').disabled=current.year===1200&&current.month===1;
                get('[data-date-next]').disabled=current.year===1600&&current.month===12;
                if(focus) (grid.querySelector('[aria-pressed="true"]')||grid.querySelector('[aria-current="date"]')||grid.querySelector('button')).focus();
            }
            function move(amount){const index=current.year*12+current.month-1+amount;current={year:Math.floor(index/12),month:index%12+1};draw();}
            trigger.addEventListener('click',()=>{if(!popup.hidden){close();return;}current=parts(parse(input.value)||parse(today()));popup.hidden=false;trigger.setAttribute('aria-expanded','true');draw(true);});
            get('[data-date-prev]').addEventListener('click',()=>move(-1));get('[data-date-next]').addEventListener('click',()=>move(1));
            monthSelect.addEventListener('change',()=>{current.month=Number(monthSelect.value);draw();});yearSelect.addEventListener('change',()=>{current.year=Number(yearSelect.value);draw();});
            get('[data-date-today]').addEventListener('click',()=>choose(today()));get('[data-date-clear]').addEventListener('click',()=>choose(''));get('[data-date-close]').addEventListener('click',()=>{close();trigger.focus();});
            document.addEventListener('click',event=>{if(!field.contains(event.target))close();});
            popup.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();close();trigger.focus();return;}
                const buttons=[...grid.querySelectorAll('button')], index=buttons.indexOf(document.activeElement);
                const shift={ArrowLeft:1,ArrowRight:-1,ArrowDown:7,ArrowUp:-7}[event.key];
                if(index>=0&&shift){event.preventDefault();buttons[Math.max(0,Math.min(buttons.length-1,index+shift))].focus();}
                if(event.key==='Tab'){const items=[...popup.querySelectorAll('button:not(:disabled),select')];const first=items[0],last=items[items.length-1];if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}}
            });
            input.form?.addEventListener('reset',()=>setTimeout(()=>{close();refresh();},0));refresh();
        });
    }
    return {parts,parse,monthDays,display,today,mount};
});
