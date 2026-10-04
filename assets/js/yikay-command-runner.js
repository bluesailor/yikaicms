/**
 * Blox 薄命令层（r11，GrapesJS UndoGroup 思想的最小实现）。
 *
 * 定位：编辑器的结构修改已由 Alpine watcher 统一收尾（dirty/历史快照/预览调度
 * 每 tick 合并——多步同步修改天然一个历史项、一次预览）。本模块补的是缺口：
 *   1. 失败回滚——mutate 中途抛异常时恢复快照，不把半改的树留给 watcher 入历史；
 *   2. 动作命名——每个结构命令有名字（错误上报/未来历史标签的挂点）；
 *   3. 防重入——命令执行期拒绝并发命令（有状态操作的 stop 语义雏形）。
 *
 * 契约：
 *   - mutate 必须是同步函数（异步动作先完成 IO，再把「应用」部分包进 execute）；
 *   - 嵌套 execute（命令内部调用其它命令）被吸收进外层——只有最外层捕获快照/回滚，
 *     整组仍是一个历史项；
 *   - hooks.restore 的实现方须自行抑制历史记录（如 _historyApplying），
 *     回滚本身绝不能成为一条新历史。
 *
 * hooks: {
 *   capture(): any                     — 捕获当前文档+选择快照
 *   restore(snapshot): void           — 恢复快照（实现方抑制 watcher 历史）
 *   onError?(name, error): void       — 回滚后的用户提示/上报
 * }
 */
(function (global) {
    "use strict";

    function BloxCommandRunner(hooks) {
        hooks = hooks || {};
        if (typeof hooks.capture !== "function" || typeof hooks.restore !== "function") {
            throw new Error("BloxCommandRunner requires capture/restore hooks");
        }
        this.hooks = hooks;
        this.depth = 0;
        this.running = null;
    }

    BloxCommandRunner.prototype.isRunning = function () {
        return this.depth > 0;
    };

    /** 当前最外层命令名（嵌套时仍是外层名），空闲时 null */
    BloxCommandRunner.prototype.current = function () {
        return this.running;
    };

    /** opts.silent: 回滚后不调 onError（调用方自己处理用户提示，如模板面板的错误条） */
    BloxCommandRunner.prototype.execute = function (name, mutate, opts) {
        if (typeof mutate !== "function") {
            return { ok: false, reason: "no-mutate" };
        }
        var silent = !!(opts && opts.silent);
        // 嵌套命令吸收进外层：不重复捕获快照，异常继续向外层冒泡由外层统一回滚
        if (this.depth > 0) {
            this.depth++;
            try {
                return { ok: true, result: mutate(), nested: true };
            } finally {
                this.depth--;
            }
        }
        var snapshot = this.hooks.capture();
        this.depth++;
        this.running = String(name || "command");
        try {
            var result = mutate();
            return { ok: true, result: result };
        } catch (error) {
            // restore 自身抛异常 = 致命（快照都恢复不了），直接向上冒泡且不发
            // onError——那个钩子的语义是「已回滚，请提示用户」，回滚失败时不成立
            this.hooks.restore(snapshot);
            if (!silent && typeof this.hooks.onError === "function") {
                this.hooks.onError(this.running, error);
            }
            return { ok: false, error: error };
        } finally {
            this.depth--;
            this.running = null;
        }
    };

    if (typeof module !== "undefined" && module.exports) {
        module.exports = BloxCommandRunner;
    }
    global.BloxCommandRunner = BloxCommandRunner;
})(typeof window !== "undefined" ? window : globalThis);
