(function () {
    if (!window.Chart || !Chart._adapters || !Chart._adapters._date) return;
    var units = {millisecond:1,second:1000,minute:60000,hour:3600000,day:86400000,week:604800000};
    function date(value) {
        if (value instanceof Date) return new Date(value.getTime());
        if (typeof value === 'number') return new Date(value);
        if (typeof value === 'string') return new Date(value.replace(' ', 'T'));
        return new Date(value);
    }
    Chart._adapters._date.override({
        _id: 'worklog-native-date',
        formats: function () { return {datetime:'dd/MM/yyyy HH:mm:ss',millisecond:'HH:mm:ss.SSS',second:'HH:mm:ss',minute:'HH:mm',hour:'dd/MM HH:mm',day:'dd/MM/yyyy',week:'dd/MM/yyyy',month:'MM/yyyy',quarter:'MM/yyyy',year:'yyyy'}; },
        parse: function (value) { var d=date(value); return isNaN(d.getTime()) ? null : d.getTime(); },
        format: function (time) { return date(time).toLocaleString('pt-PT'); },
        add: function (time, amount, unit) { var d=date(time); if(unit==='month') d.setMonth(d.getMonth()+amount); else if(unit==='quarter') d.setMonth(d.getMonth()+amount*3); else if(unit==='year') d.setFullYear(d.getFullYear()+amount); else d=new Date(d.getTime()+amount*(units[unit]||1)); return d.getTime(); },
        diff: function (max, min, unit) { if(unit==='month') return (date(max).getFullYear()-date(min).getFullYear())*12+date(max).getMonth()-date(min).getMonth(); if(unit==='quarter') return this.diff(max,min,'month')/3; if(unit==='year') return this.diff(max,min,'month')/12; return (date(max)-date(min))/(units[unit]||1); },
        startOf: function (time, unit, weekday) { var d=date(time); if(unit==='year'){d.setMonth(0,1);d.setHours(0,0,0,0);} else if(unit==='quarter'){d.setMonth(Math.floor(d.getMonth()/3)*3,1);d.setHours(0,0,0,0);} else if(unit==='month'){d.setDate(1);d.setHours(0,0,0,0);} else if(unit==='week'||unit==='isoWeek'){var target=unit==='isoWeek'?(weekday||1):0;d.setDate(d.getDate()-((d.getDay()-target+7)%7));d.setHours(0,0,0,0);} else if(unit==='day'){d.setHours(0,0,0,0);} else if(unit==='hour'){d.setMinutes(0,0,0);} else if(unit==='minute'){d.setSeconds(0,0);} else if(unit==='second'){d.setMilliseconds(0);} return d.getTime(); },
        endOf: function (time, unit) { return this.add(this.startOf(time,unit),1,unit)-1; }
    });
})();
