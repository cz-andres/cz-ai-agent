<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CZ AI Agent</title>
    <style>
        :root {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #202124;
            background: #f5f6f8;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 18px 24px;
            background: #ffffff;
            border-bottom: 1px solid #e2e5e9;
        }

        .brand {
            font-size: 18px;
            font-weight: 700;
        }

        .subtitle {
            margin-top: 4px;
            color: #737780;
            font-size: 13px;
        }

        button, textarea {
            font: inherit;
        }

        button {
            cursor: pointer;
        }

        button:disabled {
            cursor: not-allowed;
            opacity: 0.55;
        }

        .secondary-button {
            padding: 9px 13px;
            background: #ffffff;
            border: 1px solid #d9dde3;
            border-radius: 8px;
        }

        main {
            width: 100%;
            max-width: 900px;
            flex: 1;
            margin: 0 auto;
            padding: 28px 20px 20px;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        #messages {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 20px;
            padding-bottom: 24px;
        }

        .welcome {
            margin: auto 0;
            padding: 36px 12px;
            text-align: center;
        }

        .welcome h1 {
            margin-bottom: 10px;
            font-size: 28px;
            letter-spacing: -0.7px;
        }

        .welcome p {
            max-width: 490px;
            margin: 0 auto;
            line-height: 1.6;
            color: #666b75;
        }

        .message {
            max-width: 85%;
            padding: 14px 17px;
            border-radius: 14px;
            line-height: 1.65;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .message.user {
            align-self: flex-end;
            background: #e7edff;
            border: 1px solid #d8e2ff;
        }

        .message.assistant {
            align-self: flex-start;
            background: #ffffff;
            border: 1px solid #e2e5e9;
        }

        .message-label {
            margin-bottom: 5px;
            font-size: 12px;
            font-weight: 700;
            color: #6c7280;
        }

        .message-error {
            color: #a12c2c;
            background: #fff2f2;
            border-color: #f1d1d1;
        }

        .message-meta {
            margin-top: 10px;
            font-size: 11px;
            color: #7c818b;
        }

        .composer {
            padding: 12px;
            background: #ffffff;
            border: 1px solid #dfe2e7;
            border-radius: 14px;
            box-shadow: 0 4px 18px rgba(20, 30, 50, 0.04);
        }

        textarea {
            display: block;
            width: 100%;
            min-height: 70px;
            max-height: 200px;
            padding: 8px;
            resize: vertical;
            border: 0;
            outline: none;
            background: transparent;
            line-height: 1.5;
        }

        .composer-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding-top: 8px;
        }

        .hint, #status {
            font-size: 12px;
            color: #777d87;
        }

        #send-button {
            padding: 10px 18px;
            color: #ffffff;
            background: #202124;
            border: 0;
            border-radius: 9px;
        }

        #send-button:hover:not(:disabled) {
            background: #393b40;
        }

        footer {
            padding: 0 20px 18px;
            text-align: center;
            font-size: 12px;
            color: #898e98;
        }

        @media (max-width: 600px) {
            header {
                padding: 15px;
            }

            main {
                padding: 20px 12px 12px;
            }

            .message {
                max-width: 95%;
            }

            .welcome h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
<header>
    <div>
        <div class="brand">CZ AI Agent</div>
        <div class="subtitle">Insurance data analysis assistant</div>
    </div>
    <button id="new-chat-button" class="secondary-button" type="button">
        New chat
    </button>
</header>

<main>
    <section id="messages" aria-live="polite" aria-label="Conversation">
        <div class="welcome" id="welcome">
            <h1>What would you like to explore?</h1>
            <p>
                Ask questions about policies, premiums, periods, and patterns.
                Answers are grounded in the connected insurance database.
            </p>
        </div>
    </section>

    <form id="chat-form" class="composer">
        <textarea
            id="question"
            name="question"
            placeholder="Ask a question..."
            aria-label="Your question"
            maxlength="4000"
            required
        ></textarea>

        <div class="composer-footer">
            <span id="status" role="status">Ready</span>
            <div>
                <span class="hint">Enter to send · Shift+Enter for a new line</span>
                <button id="send-button" type="submit">Send</button>
            </div>
        </div>
    </form>
</main>

<footer>
    AI-generated responses can contain mistakes. Verify important conclusions.
</footer>

<script>
    const form = document.getElementById('chat-form');
    const questionInput = document.getElementById('question');
    const messages = document.getElementById('messages');
    const welcome = document.getElementById('welcome');
    const sendButton = document.getElementById('send-button');
    const newChatButton = document.getElementById('new-chat-button');
    const status = document.getElementById('status');

    let isLoading = false;
    let conversationHistory = [];

    function addMessage(role, content, metadata = '') {
        const message = document.createElement('div');
        message.className = `message ${role}`;

        const label = document.createElement('div');
        label.className = 'message-label';
        label.textContent = role === 'user' ? 'You' : 'AI assistant';

        const body = document.createElement('div');
        body.textContent = content;

        message.append(label, body);

        if (metadata) {
            const meta = document.createElement('div');
            meta.className = 'message-meta';
            meta.textContent = metadata;
            message.append(meta);
        }

        messages.append(message);
        message.scrollIntoView({ behavior: 'smooth', block: 'end' });

        return message;
    }

    function setLoading(loading) {
        isLoading = loading;
        sendButton.disabled = loading;
        questionInput.disabled = loading;
        newChatButton.disabled = loading;
        sendButton.textContent = loading ? 'Thinking...' : 'Send';
        status.textContent = loading
            ? 'Waiting for the AI response...'
            : 'Ready';
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (isLoading) {
            return;
        }

        const question = questionInput.value.trim();

        if (!question) {
            return;
        }

        welcome?.remove();

        const historyForRequest = conversationHistory.slice(-12);

        addMessage('user', question);
        questionInput.value = '';
        setLoading(true);

        let assistantMessage = null;

        try {
            const response = await fetch('/api/chat.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    question,
                    history: historyForRequest
                })
            });

            const data = await response.json();

            if (!response.ok) {
                const reference = data.request_id
                    ? ` (Reference: ${data.request_id})`
                    : '';
                throw new Error(
                    `${data.error || 'The request could not be completed.'}${reference}`
                );
            }

            const evidence = data.evidence;
            const evidenceSummary = evidence
                && Array.isArray(evidence.tables)
                && Number.isInteger(evidence.returned_rows)
                ? `Database: ${evidence.tables.join(', ')} · ${evidence.returned_rows} row${evidence.returned_rows === 1 ? '' : 's'}`
                : 'Database evidence metadata was not returned.';
            const diagnostics = data.diagnostics;
            const diagnosticsSummary = diagnostics
                && ['high', 'medium', 'low'].includes(diagnostics.retrieval_confidence)
                && Number.isFinite(diagnostics.timings_ms?.total)
                ? `Schema match: ${diagnostics.retrieval_confidence} · ${diagnostics.timings_ms.total} ms`
                : 'Diagnostics unavailable.';

            assistantMessage = addMessage(
                'assistant',
                data.answer,
                `Provider: ${data.provider} · Model: ${data.model} · ${evidenceSummary} · ${diagnosticsSummary}`
            );

            conversationHistory.push(
                { role: 'user', content: question },
                { role: 'assistant', content: data.answer }
            );
        } catch (error) {
            assistantMessage = addMessage(
                'assistant message-error',
                error.message || 'Something went wrong. Please try again.'
            );
        } finally {
            setLoading(false);
            questionInput.focus();
        }
    });

    questionInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();

            if (!isLoading) {
                form.requestSubmit();
            }
        }
    });

    newChatButton.addEventListener('click', () => {
        messages.replaceChildren();
        conversationHistory = [];

        const welcomeMessage = document.createElement('div');
        welcomeMessage.className = 'welcome';
        welcomeMessage.innerHTML =
            '<h1>What would you like to explore?</h1>' +
            '<p>Ask questions about policies, premiums, periods, and patterns. ' +
            'Answers are grounded in the connected insurance database.</p>';

        messages.append(welcomeMessage);
        questionInput.value = '';
        questionInput.focus();
        status.textContent = 'Ready';
    });
</script>
</body>
</html>
