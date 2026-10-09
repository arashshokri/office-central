const {readFileSync}=require('node:fs');
const vm=require('node:vm');
const {test}=require('node:test');
const assert=require('node:assert/strict');
const sandbox={module:{exports:{}},Intl,Date,Map};
vm.runInNewContext(readFileSync('public/assets/jalali-picker.js','utf8'),sandbox);
const calendar=sandbox.module.exports;
test('Jalali conversion matches Nowruz and the reported panel date',()=>{
    assert.equal(calendar.display('2026-10-09'),'۱۴۰۵/۰۷/۱۷');
    assert.equal(calendar.display('2025-03-21'),'۱۴۰۴/۰۱/۰۱');
    assert.equal(calendar.display('2024-03-20'),'۱۴۰۳/۰۱/۰۱');
});
test('Esfand respects leap years and month boundaries stay contiguous',()=>{
    assert.equal(calendar.monthDays(1403,12).length,30);
    assert.equal(calendar.monthDays(1404,12).length,29);
    assert.equal(calendar.monthDays(1403,12)[29].date,'2025-03-20');
    for(let year=1398;year<=1410;year++)for(let month=1;month<=12;month++){
        const days=calendar.monthDays(year,month);
        days.forEach((day,i)=>{const p=calendar.parts(new Date(day.date+'T00:00:00Z'));assert.equal(p.year,year);assert.equal(p.month,month);assert.equal(p.day,i+1);});
    }
});
test('malformed dates cannot silently become another expiry date',()=>{
    for(const date of ['2026-02-30','۱۴۰۵/۰۷/۱۷','bad','2026-2-3',''])assert.equal(calendar.parse(date),null);
});
