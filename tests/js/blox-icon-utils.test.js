"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const icons = require("../../assets/js/blox-icon-utils.js");

test("legacy homepage icon names resolve to available Tabler classes", () => {
    assert.equal(icons.className("check-circle"), "ti ti-circle-check");
    assert.equal(icons.className("academic-cap"), "ti ti-school");
    assert.equal(icons.className("cog"), "ti ti-settings");
});

test("provider prefixes and invalid values stay bounded", () => {
    assert.equal(icons.className("bi:check-circle"), "bi bi-check-circle");
    assert.equal(icons.className("tabler:star"), "ti ti-star");
    assert.equal(icons.className("unknown:value"), "ti ti-star");
    assert.equal(icons.className("none"), "ti ti-ban");
});

test("all recommended advantage icons resolve to bundled font glyphs", () => {
    const css = fs.readFileSync(
        path.join(__dirname, "../../assets/tabler/tabler-icons.min.css"),
        "utf8"
    );
    const recommended = [
        "check-circle", "shield-check", "academic-cap", "briefcase", "users",
        "star", "heart", "globe", "clock", "cog", "chart-bar", "thumb-up",
        "phone", "bolt", "sparkles", "truck",
    ];

    for (const value of recommended) {
        const className = icons.className(value).split(" ").pop();
        assert.match(css, new RegExp("\\." + className + ":before\\{"), value);
    }
});
