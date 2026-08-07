"use strict";
const test = require("node:test");
const assert = require("node:assert/strict");
const BloxCommandRunner = require("../../assets/js/blox-command-runner.js");

function fixture(overrides) {
    const calls = { captures: 0, restores: [], errors: [] };
    const runner = new BloxCommandRunner(Object.assign({
        capture: () => { calls.captures += 1; return "snap-" + calls.captures; },
        restore: (snap) => { calls.restores.push(snap); },
        onError: (name, err) => { calls.errors.push([name, err.message]); },
    }, overrides || {}));
    return { runner, calls };
}

test("成功命令：一次捕获、零回滚、返回结果", () => {
    const { runner, calls } = fixture();
    const out = runner.execute("add-element", () => 42);
    assert.deepEqual(out, { ok: true, result: 42 });
    assert.equal(calls.captures, 1);
    assert.deepEqual(calls.restores, []);
});

test("失败命令：恢复捕获的快照并上报命令名", () => {
    const { runner, calls } = fixture();
    const out = runner.execute("insert-template", () => { throw new Error("boom"); });
    assert.equal(out.ok, false);
    assert.deepEqual(calls.restores, ["snap-1"]);
    assert.deepEqual(calls.errors, [["insert-template", "boom"]]);
    assert.equal(runner.isRunning(), false);
});

test("嵌套命令被吸收：只有最外层捕获快照，异常由外层统一回滚", () => {
    const { runner, calls } = fixture();
    const ok = runner.execute("outer", () => {
        const inner = runner.execute("inner", () => "x");
        assert.equal(inner.nested, true);
        return inner.result;
    });
    assert.equal(ok.result, "x");
    assert.equal(calls.captures, 1, "嵌套不得二次捕获");

    const fail = runner.execute("outer-fail", () => {
        runner.execute("inner-throws", () => { throw new Error("deep"); });
    });
    assert.equal(fail.ok, false);
    assert.deepEqual(calls.restores, ["snap-2"], "只回滚外层快照一次");
    assert.deepEqual(calls.errors.at(-1), ["outer-fail", "deep"], "以外层命令名上报");
});

test("命令执行期 current() 暴露外层名，结束后清空", () => {
    const { runner } = fixture();
    runner.execute("layout", () => {
        assert.equal(runner.current(), "layout");
        runner.execute("nested", () => {
            assert.equal(runner.current(), "layout", "嵌套期仍是外层名");
        });
    });
    assert.equal(runner.current(), null);
});

test("restore 抛异常也不吞 onError，且 runner 状态复原", () => {
    const { runner, calls } = fixture({
        restore: () => { throw new Error("restore-broken"); },
    });
    assert.throws(() => runner.execute("cmd", () => { throw new Error("boom"); }), /restore-broken/);
    assert.equal(runner.isRunning(), false);
    assert.deepEqual(calls.errors, [], "restore 失败属于致命错误，向上抛而非静默");
});

test("silent 选项：照常回滚但不调 onError（调用方自理提示）", () => {
    const { runner, calls } = fixture();
    const out = runner.execute("insert-template", () => { throw new Error("io"); }, { silent: true });
    assert.equal(out.ok, false);
    assert.deepEqual(calls.restores, ["snap-1"], "回滚不受 silent 影响");
    assert.deepEqual(calls.errors, [], "silent 跳过 onError");
});

test("缺 hooks 或 mutate 非函数的防御", () => {
    assert.throws(() => new BloxCommandRunner({}), /capture\/restore/);
    const { runner } = fixture();
    assert.deepEqual(runner.execute("x", null), { ok: false, reason: "no-mutate" });
});
