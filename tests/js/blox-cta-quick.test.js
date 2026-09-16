const { test } = require('node:test');
const assert = require('node:assert/strict');
const { target, methods } = require('../../assets/js/blox-cta-quick');
function editor(node) {
    return Object.assign({ sections: [{ settings: {}, columns: [{ elements: [{ type: 'container', data: { children: [node] } }] }] }], selectedSi: 0,
        selEl: null, ctaQuickSeeds: {title:'Site title',text:'Site text',btn_text:'Contact',btn_url:'/contact.html'},
        elSchema: () => ({defaults:{}}), runCommand(name, fn) { fn.call(this); } }, methods);
}
test('a nested CTA has one content entry; ambiguous sections are not guessed', () => {
    const node = {type:'cta',data:{}}, ctx = editor(node);
    assert.equal(ctx.ctaQuickTarget(), node);
    assert.equal(target(ctx.sections[0], ctx.sections[0].columns[0].elements[0]), node);
    ctx.sections[0].columns[0].elements.push({type:'cta',data:{}});
    assert.equal(ctx.ctaQuickTarget(), null);
    ctx.selEl = node;
    assert.equal(ctx.ctaQuickTarget(), node);
});
test('editing inherited CTA content snapshots siblings before leaving site defaults', () => {
    const node = {type:'cta',data:{use_home_text:true,title:'Stale'}}, ctx = editor(node);
    assert.equal(ctx.ctaQuickValue('title'), 'Site title');
    ctx.setCtaQuickValue('title','New');
    assert.deepEqual(node.data, {use_home_text:false,title:'New',text:'Site text',btn_text:'Contact',btn_url:'/contact.html'});
});
test('legacy content edits touch only their override and use the outer background', () => {
    const node = {type:'home-block',data:{block_type:'cta'}}, ctx = editor(node);
    ctx.setCtaQuickValue('text','Body');
    assert.deepEqual(node.data, {block_type:'cta',override_description:'Body'});
    assert.equal(ctx.ctaQuickBackground(),ctx.sections[0].settings);
});
test('media dialog cannot update a newly selected CTA', () => {
    const node = {type:'cta',data:{}}, ctx = editor(node);
    ctx.openMedia = fn => { ctx.callback = fn; };
    ctx.replaceCtaQuickBackground();
    ctx.selEl = {type:'cta',data:{}};
    ctx.callback('/stale.jpg');
    assert.equal(node.data.bg_image,undefined);
});
test('legacy background migration preserves the visible image and inverse opacity', () => {
    const node = {type:'home-block',data:{block_type:'cta',bg_image:'/old.jpg',bg_opacity:35,text_light:true}}, ctx = editor(node);
    assert.equal(ctx.ctaQuickBackground().bg_image,'/old.jpg');
    ctx.setCtaQuickBackground('/new.jpg');
    assert.equal(ctx.sections[0].settings.bg_image,'/new.jpg');
    assert.equal(ctx.sections[0].settings.bg_overlay_opacity,65);
    assert.equal(ctx.sections[0].settings.text_tone,'light');
    assert.equal(node.data.bg_image,undefined);
});
