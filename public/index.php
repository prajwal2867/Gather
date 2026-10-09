<?php
declare(strict_types=1);

try {
    require_once dirname(__DIR__) . '/bootstrap.php';
} catch (Throwable $exception) {
    error_log($exception->__toString());
    http_response_code(500);
    $startupError = 'Unable to connect to the chat database. Check the setup instructions in README.md.';
}

$initialMembership = $_SESSION['membership'] ?? null;
$appConfig = [
    'csrfToken' => $_SESSION['csrf_token'] ?? '',
    'membership' => is_array($initialMembership) ? [
        'nickname' => $initialMembership['nickname'],
        'is_admin' => $initialMembership['is_admin'],
    ] : null,
    'startupError' => $startupError ?? null,
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7f8fc">
    <title>Gather — rooms for your people</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
    <script>
        window.GATHER_CONFIG = <?= json_encode($appConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="assets/app.js" defer></script>
</head>
<body>
    <main class="app-shell">
        <header class="topbar">
            <a class="brand" href="./" aria-label="Gather home">
                <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span><span></span></span>
                <span>gather<span class="brand-period">.</span></span>
            </a>
            <div class="topbar-note"><span class="status-dot"></span> A little closer, wherever you are</div>
        </header>

        <section class="home-view" id="homeView">
            <div class="hero">
                <div class="hero-copy">
                    <div class="eyebrow"><span class="eyebrow-line"></span> YOUR PEOPLE, ONE PLACE</div>
                    <h1>Good conversations<br>start with <span>a little hello.</span></h1>
                    <p class="hero-description">Make a room for your people. Share a code, drop a message, and pick up right where you left off.</p>
                    <div class="hero-proof">
                        <div class="avatar-stack" aria-hidden="true"><span class="avatar peach">J</span><span class="avatar blue">M</span><span class="avatar green">A</span><span class="avatar lilac">S</span></div>
                        <span>Made for the people who get you</span>
                    </div>
                </div>
                <div class="hero-art" aria-hidden="true">
                    <div class="art-orbit orbit-one"></div><div class="art-orbit orbit-two"></div>
                    <div class="art-card art-card-main">
                        <div class="art-card-top"><span class="art-dot"></span><span class="art-dot"></span><span class="art-dot"></span><span class="art-card-label">the little things</span></div>
                        <div class="art-bubble bubble-left">made it home 🌿</div>
                        <div class="art-bubble bubble-right">save me a seat ✨</div>
                        <div class="art-bubble bubble-left">always, obviously</div>
                        <div class="art-typing"><span></span><span></span><span></span></div>
                    </div>
                    <div class="art-sparkle sparkle-a">✳</div><div class="art-sparkle sparkle-b">✦</div><div class="art-sticker">good<br>company</div>
                </div>
            </div>

            <div class="action-grid">
                <article class="action-card create-card">
                    <div class="card-icon create-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></div>
                    <div class="card-copy"><h2>Start a room</h2><p>Make a space for your group. You’ll get a code to share.</p></div>
                    <button class="button button-primary" id="showCreate">Create room <span aria-hidden="true">↗</span></button>
                </article>
                <article class="action-card join-card">
                    <div class="card-icon join-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 12h8M12 8l4 4-4 4"/><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v13a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 18.5z"/></svg></div>
                    <div class="card-copy"><h2>Join a room</h2><p>Have a code already? Pop in and say hello.</p></div>
                    <button class="button button-secondary" id="showJoin">Enter a code <span aria-hidden="true">↗</span></button>
                </article>
            </div>

            <footer class="home-footer"><span>Just messages. No awkward camera angles.</span><span>Made for the group chat people.</span></footer>
        </section>

        <section class="room-view hidden" id="roomView" aria-live="polite">
            <div class="room-topline">
                <button class="text-button back-button" id="leaveRoom"><span aria-hidden="true">←</span> All rooms</button>
                <div class="room-top-actions">
                    <button class="text-button" id="copyCode"><span aria-hidden="true">▣</span> <span id="roomCodeLabel"></span></button>
                    <button class="icon-button" id="roomMenu" aria-label="Room options" title="Room options">•••</button>
                    <div class="room-menu hidden" id="roomMenuPanel">
                        <button id="copyInvite">Copy invite code</button>
                        <button class="danger-menu hidden" id="closeRoom">Close this room</button>
                    </div>
                </div>
            </div>
            <div class="chat-card">
                <aside class="chat-sidebar">
                    <div class="room-title-area">
                        <span class="room-kicker"><span class="status-dot"></span> YOUR ROOM</span>
                        <h1 id="roomTitle">Room name</h1>
                        <p>Good to have you here, <strong id="memberName"></strong>.</p>
                    </div>
                    <div class="sidebar-divider"></div>
                    <div class="people-heading"><span>In the room</span><span class="people-count" id="onlineCount">1</span></div>
                    <div class="presence-note"><span class="status-dot"></span> Everyone’s here in spirit</div>
                    <div class="sidebar-note"><span class="note-star">✳</span><p>Keep it kind. This is your little corner of the internet.</p></div>
                </aside>
                <section class="conversation" aria-label="Room conversation">
                    <div class="messages" id="messages">
                        <div class="empty-chat" id="emptyChat"><span class="empty-chat-icon">✳</span><strong>It’s a fresh start.</strong><span>Say something nice to get things going.</span></div>
                    </div>
                    <form class="composer" id="messageForm">
                        <label class="sr-only" for="messageInput">Your message</label>
                        <textarea id="messageInput" rows="1" maxlength="2000" placeholder="Write something lovely..." required></textarea>
                        <button class="send-button" type="submit" aria-label="Send message"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 14-7-4 14-3-6-7-1Z"/><path d="m12 13 7-8"/></svg></button>
                        <div class="composer-hint">Just press <kbd>Enter</kbd> to send <span>·</span> <kbd>Shift + Enter</kbd> for a new line</div>
                    </form>
                </section>
            </div>
        </section>

        <footer class="app-footer"><span>gather<span class="brand-period">.</span></span><span>A little space for your favorite people.</span></footer>
    </main>

    <div class="modal-backdrop hidden" id="modalBackdrop">
        <section class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
            <button class="modal-close" id="modalClose" aria-label="Close dialog">×</button>
            <div class="modal-mark" aria-hidden="true">✳</div>
            <p class="modal-kicker" id="modalKicker">MAKE YOURSELF AT HOME</p>
            <h2 id="modalTitle">Start a room</h2>
            <p class="modal-description" id="modalDescription">Give your room a name, then invite your favorite people in.</p>
            <form id="roomForm">
                <label id="roomNameLabel" for="roomName">Room name</label>
                <input id="roomName" name="room_name" maxlength="80" placeholder="e.g. Sunday catch-up" autocomplete="off">
                <label id="roomCodeFormLabel" class="hidden" for="roomCode">Room code</label>
                <input id="roomCode" name="code" class="hidden code-input" maxlength="4" placeholder="e.g. A1B2" autocomplete="off" autocapitalize="characters">
                <label for="nickname">Your name</label>
                <input id="nickname" name="nickname" maxlength="32" placeholder="What should we call you?" autocomplete="nickname" required>
                <p class="form-error hidden" id="formError" role="alert"></p>
                <button class="button button-primary modal-submit" type="submit" id="modalSubmit">Create my room <span aria-hidden="true">↗</span></button>
            </form>
        </section>
    </div>
    <div class="toast hidden" id="toast" role="status"></div>
</body>
</html>
