const { test } = require('node:test');
const assert = require('node:assert/strict');
require('../../assets/js/blox-responsive');
const {state,methods} = require('../../assets/js/blox-style-source');
const color = {key:'color',type:'color',default:''};
test('style sources distinguish inheritance, local values, tokens and winning global styles', () => {
    const source = data => state(data,color,'desktop',global.BloxResponsive,[]).source;
    assert.equal(source({}), 'theme');
    assert.equal(source({color:'#123456'}), 'local');
    assert.equal(source({color:'var(--yk-color-primary)'}), 'theme');
    assert.equal(source({color:'var(--yk-color-custom)'}), 'token');
    assert.equal(source({color:'#fff',_global_style:'card',_global_style_snapshot:{color:'#000'}}), 'global');
});
test('resetting a desktop property preserves mobile customization and reveals the default', () => {
    const ctrl = {key:'align',responsive:true,default:'left',options:{left:'Left',right:'Right',center:'Center'}};
    const ctx = Object.assign({selEl:{data:{align:{d:'right',m:'center'}}},previewDevice:'desktop',designSystem:{styles:[]},
        controlOptions:()=>ctrl.options,flushHistory(){},runCommand(name,fn){fn.call(this);}},methods);
    assert.equal(ctx.controlStyleSource(ctrl).source,'local');
    ctx.restoreControlStyle(ctrl);
    assert.equal(global.BloxResponsive.valueFor(ctx.selEl.data.align,'desktop',ctrl.options,'left'),'left');
    assert.equal(global.BloxResponsive.valueFor(ctx.selEl.data.align,'mobile',ctrl.options,'left'),'center');
    assert.equal(ctx.controlStyleSource(ctrl).source,'default');
    ctx.previewDevice='mobile';
    assert.equal(ctx.controlStyleSource(ctrl).source,'mobile');
});
