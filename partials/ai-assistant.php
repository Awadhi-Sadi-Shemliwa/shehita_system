<?php
/**
 * SHEHITA EMS — AI assistant panel
 *
 * Included once by homepage.php, so it is available on every module page.
 * Renders nothing at all when the assistant is unavailable, rather than showing
 * a bubble that only produces an error when tapped.
 *
 * Styled entirely with var(--brown-*), so it picks up each company's own brand
 * colours automatically.
 */

$ai_available = (getenv('AI_API_KEY') ?: '') !== ''
    && (int)($TENANT['ai_enabled'] ?? 1) === 1;

if (!$ai_available) {
    return;
}
?>
<style>
    .ai-fab {
        position: fixed; right: 22px; bottom: 22px; z-index: 900;
        width: 56px; height: 56px; border-radius: 50%; border: none; cursor: pointer;
        background: var(--brown-700); color: #fff; font-size: 1.35rem;
        box-shadow: 0 6px 18px rgb(0 0 0 / .22);
        display: flex; align-items: center; justify-content: center;
        transition: transform .16s, background .16s;
    }
    .ai-fab:hover { background: var(--brown-800); transform: translateY(-2px); }
    .ai-fab.open  { background: var(--brown-900); }

    /*
     * The panel is anchored to the bottom and grows upward, so the height it may
     * take is the viewport MINUS everything it must not cover: the fixed navbar
     * above it, its own offset from the bottom, and a little breathing room.
     *
     * That figure used to be hardcoded as 130px, which was ~44px too generous --
     * so on a short viewport the panel's top slid underneath the navbar and the
     * "Assistant" header simply disappeared. Deriving it from --navbar-height
     * also keeps it correct when the navbar shrinks to 60px on phones, which a
     * constant never could.
     */
    .ai-panel {
        --ai-gap-bottom: 88px;
        --ai-gap-top: 16px;

        position: fixed; right: 22px; bottom: var(--ai-gap-bottom); z-index: 900;
        width: min(390px, calc(100vw - 32px));
        max-height: min(620px, calc(100vh - var(--navbar-height, 70px)
                                     - var(--ai-gap-bottom) - var(--ai-gap-top)));
        background: #fff; border-radius: 16px; overflow: hidden;
        box-shadow: 0 18px 42px rgb(0 0 0 / .26);
        display: none; flex-direction: column;
    }
    .ai-panel.open { display: flex; }

    .ai-head {
        background: var(--navbar-bg); color: #fff; padding: 14px 18px;
        display: flex; align-items: center; gap: 10px;
    }
    .ai-head .t { font-weight: 600; font-size: .96rem; flex: 1; }
    .ai-head .sub { font-size: .75rem; opacity: .72; display: block; font-weight: 400; margin-top: 1px; }
    .ai-head button {
        background: none; border: none; color: #fff; cursor: pointer; opacity: .75;
        font-size: .9rem; padding: 4px 6px; border-radius: 5px;
    }
    .ai-head button:hover { opacity: 1; background: rgb(255 255 255 / .14); }

    .ai-log { flex: 1; overflow-y: auto; padding: 16px; background: var(--gray-50); }

    .ai-msg { margin-bottom: 13px; display: flex; }
    .ai-msg.me { justify-content: flex-end; }
    .ai-msg .bub {
        max-width: 86%; padding: 10px 14px; border-radius: 13px;
        font-size: .89rem; line-height: 1.55; white-space: pre-wrap; word-wrap: break-word;
    }
    .ai-msg.me .bub  { background: var(--brown-700); color: #fff; border-bottom-right-radius: 4px; }
    .ai-msg.bot .bub { background: #fff; color: var(--gray-800); border: 1px solid var(--brown-200);
                       border-bottom-left-radius: 4px; }
    .ai-msg.err .bub { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    .ai-hint { font-size: .73rem; color: var(--gray-500); margin-top: 5px; padding-left: 3px; }

    .ai-suggest { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 10px; }
    .ai-suggest button {
        background: #fff; border: 1px solid var(--brown-300); color: var(--brown-800);
        border-radius: 999px; padding: 6px 13px; font-size: .8rem; cursor: pointer;
        font-family: inherit;
    }
    .ai-suggest button:hover { background: var(--brown-100); border-color: var(--brown-600); }

    .ai-form { display: flex; gap: 8px; padding: 12px; border-top: 1px solid var(--brown-200); background: #fff; }
    .ai-form textarea {
        flex: 1; resize: none; border: 1.5px solid var(--brown-200); border-radius: 9px;
        padding: 9px 12px; font-family: inherit; font-size: .89rem; max-height: 96px;
        color: var(--gray-800);
    }
    .ai-form textarea:focus { outline: none; border-color: var(--brown-600); }
    .ai-form button {
        border: none; border-radius: 9px; background: var(--brown-700); color: #fff;
        padding: 0 16px; cursor: pointer; font-size: 1rem;
    }
    .ai-form button:disabled { opacity: .5; cursor: default; }

    .ai-dots span {
        display: inline-block; width: 6px; height: 6px; margin-right: 3px; border-radius: 50%;
        background: var(--brown-500); animation: aiBlink 1.2s infinite;
    }
    .ai-dots span:nth-child(2) { animation-delay: .2s; }
    .ai-dots span:nth-child(3) { animation-delay: .4s; }
    @keyframes aiBlink { 0%, 60%, 100% { opacity: .25 } 30% { opacity: 1 } }

    @media (max-width: 520px) {
        .ai-panel {
            right: 10px; left: 10px; width: auto;
            --ai-gap-bottom: 80px;
        }
        .ai-fab { right: 14px; bottom: 14px; }
    }

    /* Small phones. The panel takes nearly the whole screen, because a 360px
       device has no room for a floating window beside the content. */
    @media (max-width: 479px) {
        .ai-panel {
            right: 8px; left: 8px;
            --ai-gap-bottom: 72px;
            --ai-gap-top: 8px;
        }
        .ai-fab {
            right: 12px; bottom: 12px;
            width: 50px; height: 50px; font-size: 1.2rem;
        }
        .ai-log { padding: 12px; }
        .ai-msg .bub { max-width: 92%; }
    }
</style>

<button class="ai-fab" id="aiFab" type="button" aria-label="Open the assistant" title="Ask the assistant">
    <i class="fas fa-comment-dots"></i>
</button>

<div class="ai-panel" id="aiPanel" role="dialog" aria-label="Assistant">
    <div class="ai-head">
        <span class="t">
            Assistant
            <span class="sub">Ask about your data or how to do something</span>
        </span>
        <button type="button" id="aiClear" title="Start a new conversation"><i class="fas fa-rotate-left"></i></button>
        <button type="button" id="aiClose" title="Close"><i class="fas fa-xmark"></i></button>
    </div>

    <div class="ai-log" id="aiLog"></div>

    <form class="ai-form" id="aiForm">
        <textarea id="aiInput" rows="1" placeholder="Ask a question…" maxlength="2000"></textarea>
        <button type="submit" id="aiSend" aria-label="Send"><i class="fas fa-paper-plane"></i></button>
    </form>
</div>

<script>
(function () {
    var csrf  = <?= json_encode(csrf_token()) ?>;
    var lang  = (localStorage.getItem('preferredLanguage') || 'en');
    var fab   = document.getElementById('aiFab');
    var panel = document.getElementById('aiPanel');
    var log   = document.getElementById('aiLog');
    var form  = document.getElementById('aiForm');
    var input = document.getElementById('aiInput');
    var send  = document.getElementById('aiSend');
    var busy  = false;

    var greeting = lang === 'sw'
        ? 'Habari! Naweza kukusaidia kutumia mfumo, au kukujibu maswali kuhusu taarifa za kampuni yako.'
        : 'Hello. I can help you use the system, or answer questions about your company’s records.';

    var suggestions = lang === 'sw'
        ? ['Tuna wateja wangapi?', 'Ankara ambazo hazijalipwa', 'Usajili wetu unaisha lini?']
        : ['How many customers do we have?', 'Which invoices are unpaid?', 'When does our subscription end?'];

    function scroll() { log.scrollTop = log.scrollHeight; }

    function addMessage(text, kind) {
        var wrap = document.createElement('div');
        wrap.className = 'ai-msg ' + kind;
        var bub = document.createElement('div');
        bub.className = 'bub';
        bub.textContent = text;          // textContent, never innerHTML: the answer
        wrap.appendChild(bub);           // is model output and must not be markup
        log.appendChild(wrap);
        scroll();
        return bub;
    }

    function addSuggestions() {
        var box = document.createElement('div');
        box.className = 'ai-suggest';
        suggestions.forEach(function (s) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = s;
            b.addEventListener('click', function () {
                box.remove();
                ask(s);
            });
            box.appendChild(b);
        });
        log.appendChild(box);
        scroll();
    }

    function greet() {
        log.innerHTML = '';
        addMessage(greeting, 'bot');
        addSuggestions();
    }

    function ask(question) {
        if (busy || !question.trim()) { return; }
        busy = true;
        send.disabled = true;

        addMessage(question, 'me');
        input.value = '';
        input.style.height = 'auto';

        var thinking = addMessage('', 'bot');
        thinking.innerHTML = '<span class="ai-dots"><span></span><span></span><span></span></span>';

        fetch('ai/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: csrf, message: question })
        })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
        .then(function (res) {
            thinking.parentElement.remove();
            if (res.data && res.data.answer) {
                addMessage(res.data.answer, 'bot');
            } else {
                addMessage(
                    (res.data && res.data.error) || 'The assistant could not answer that.',
                    'err'
                );
            }
        })
        .catch(function () {
            thinking.parentElement.remove();
            addMessage('Could not reach the assistant. Please check your connection.', 'err');
        })
        .finally(function () {
            busy = false;
            send.disabled = false;
            input.focus();
        });
    }

    fab.addEventListener('click', function () {
        var open = panel.classList.toggle('open');
        fab.classList.toggle('open', open);
        fab.innerHTML = open ? '<i class="fas fa-xmark"></i>' : '<i class="fas fa-comment-dots"></i>';
        if (open) {
            if (!log.children.length) { greet(); }
            input.focus();
        }
    });

    document.getElementById('aiClose').addEventListener('click', function () { fab.click(); });

    document.getElementById('aiClear').addEventListener('click', function () {
        fetch('ai/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: csrf, message: 'reset', reset: true })
        }).catch(function () {});
        greet();
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        ask(input.value);
    });

    // Enter sends, Shift+Enter makes a new line.
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            ask(input.value);
        }
    });

    input.addEventListener('input', function () {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 96) + 'px';
    });
})();
</script>
