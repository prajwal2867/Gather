(() => {
    const config = window.GATHER_CONFIG;
    const homeView = document.querySelector('#homeView');
    const roomView = document.querySelector('#roomView');
    const modal = document.querySelector('#modalBackdrop');
    const form = document.querySelector('#roomForm');
    const formError = document.querySelector('#formError');
    const toast = document.querySelector('#toast');
    const messages = document.querySelector('#messages');
    const messageForm = document.querySelector('#messageForm');
    const messageInput = document.querySelector('#messageInput');
    let modalMode = 'create';
    let room = null;
    let lastMessageId = 0;
    let pollTimer = null;
    let toastTimer = null;

    const showToast = (text) => {
        toast.textContent = text;
        toast.classList.remove('hidden');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => toast.classList.add('hidden'), 2600);
    };

    async function api(action, method = 'GET', payload = null, query = '') {
        const options = { method, headers: { Accept: 'application/json' } };
        if (method === 'POST') {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = config.csrfToken;
            options.body = JSON.stringify(payload || {});
        }
        const response = await fetch(`api.php?action=${encodeURIComponent(action)}${query}`, options);
        let data;
        try {
            data = await response.json();
        } catch {
            throw new Error('The server returned an unreadable response. Please try again.');
        }
        if (!response.ok) {
            const error = new Error(data.error || 'Something went wrong. Please try again.');
            error.code = data.code;
            throw error;
        }
        return data;
    }

    function openModal(mode) {
        modalMode = mode;
        form.reset();
        formError.textContent = '';
        formError.classList.add('hidden');
        const creating = mode === 'create';
        document.querySelector('#modalKicker').textContent = creating ? 'MAKE YOURSELF AT HOME' : 'YOU’RE INVITED';
        document.querySelector('#modalTitle').textContent = creating ? 'Start a room' : 'Join your people';
        document.querySelector('#modalDescription').textContent = creating
            ? 'Give your room a name, then invite your favorite people in.'
            : 'Enter the room code someone shared with you.';
        document.querySelector('#roomName').classList.toggle('hidden', !creating);
        document.querySelector('#roomNameLabel').classList.toggle('hidden', !creating);
        document.querySelector('#roomCode').classList.toggle('hidden', creating);
        document.querySelector('#roomCodeFormLabel').classList.toggle('hidden', creating);
        document.querySelector('#modalSubmit').innerHTML = creating
            ? 'Create my room <span aria-hidden="true">↗</span>'
            : 'Join the room <span aria-hidden="true">↗</span>';
        modal.classList.remove('hidden');
        window.setTimeout(() => document.querySelector(creating ? '#roomName' : '#roomCode').focus(), 50);
    }

    function closeModal() {
        modal.classList.add('hidden');
    }

    function enterRoom(data) {
        room = data.room;
        lastMessageId = 0;
        document.body.classList.add('room-active');
        homeView.classList.add('hidden');
        roomView.classList.remove('hidden');
        document.querySelector('#roomTitle').textContent = room.name;
        document.querySelector('#roomCodeLabel').textContent = room.code;
        document.querySelector('#memberName').textContent = room.nickname;
        document.querySelector('#closeRoom').classList.toggle('hidden', !room.is_admin);
        document.querySelector('#roomMenuPanel').classList.add('hidden');
        messages.replaceChildren();
        const empty = document.createElement('div');
        empty.className = 'empty-chat';
        empty.id = 'emptyChat';
        empty.innerHTML = '<span class="empty-chat-icon">✳</span><strong>It’s a fresh start.</strong><span>Say something nice to get things going.</span>';
        messages.append(empty);
        closeModal();
        messageInput.focus();
        pollMessages();
    }

    function renderMessage(message) {
        const empty = document.querySelector('#emptyChat');
        if (empty) empty.remove();
        const wrapper = document.createElement('article');
        wrapper.className = `message ${message.is_mine ? 'message-mine' : ''}`;
        const avatar = document.createElement('span');
        avatar.className = `message-avatar avatar-color-${(message.nickname.codePointAt(0) || 0) % 5}`;
        avatar.textContent = Array.from(message.nickname)[0].toUpperCase();
        const content = document.createElement('div');
        content.className = 'message-content';
        const meta = document.createElement('div');
        meta.className = 'message-meta';
        const author = document.createElement('strong');
        author.textContent = message.is_mine ? 'You' : message.nickname;
        const time = document.createElement('time');
        const parsed = new Date(message.created_at.replace(' ', 'T'));
        time.dateTime = parsed.toISOString();
        time.textContent = parsed.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        const body = document.createElement('p');
        body.textContent = message.body;
        meta.append(author, time);
        content.append(meta, body);
        if (message.is_mine) wrapper.append(content, avatar);
        else wrapper.append(avatar, content);
        messages.append(wrapper);
        messages.scrollTop = messages.scrollHeight;
    }

    function showHome() {
        window.clearTimeout(pollTimer);
        room = null;
        document.body.classList.remove('room-active');
        roomView.classList.add('hidden');
        homeView.classList.remove('hidden');
    }

    async function pollMessages() {
        if (!room) return;
        try {
            const data = await api('messages', 'GET', null, `&after=${lastMessageId}`);
            data.messages.forEach((message) => {
                renderMessage(message);
                lastMessageId = Math.max(lastMessageId, message.id);
            });
            document.querySelector('#onlineCount').textContent = data.online_count;
        } catch (error) {
            if (error.code === 'room_closed') {
                showHome();
                showToast('This room has been closed by its creator.');
                return;
            }
            if (error.message.includes('Join a room')) {
                showHome();
                return;
            }
            showToast(error.message);
        }
        pollTimer = window.setTimeout(pollMessages, 2500);
    }

    document.querySelector('#showCreate').addEventListener('click', () => openModal('create'));
    document.querySelector('#showJoin').addEventListener('click', () => openModal('join'));
    document.querySelector('#modalClose').addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal();
    });
    document.querySelector('#roomCode').addEventListener('input', (event) => {
        event.target.value = event.target.value.toUpperCase().replace(/[^A-F0-9]/g, '').slice(0, 4);
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submit = document.querySelector('#modalSubmit');
        submit.disabled = true;
        formError.classList.add('hidden');
        try {
            const payload = {
                nickname: document.querySelector('#nickname').value.trim(),
                ...(modalMode === 'create'
                    ? { room_name: document.querySelector('#roomName').value.trim() }
                    : { code: document.querySelector('#roomCode').value.trim() }),
            };
            const data = await api(modalMode, 'POST', payload);
            enterRoom(data);
        } catch (error) {
            formError.textContent = error.message;
            formError.classList.remove('hidden');
        } finally {
            submit.disabled = false;
        }
    });

    messageForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const body = messageInput.value.trim();
        if (!body || !room) return;
        const send = messageForm.querySelector('button');
        send.disabled = true;
        try {
            await api('send', 'POST', { body });
            messageInput.value = '';
            messageInput.style.height = 'auto';
            await pollMessages();
        } catch (error) {
            showToast(error.message);
        } finally {
            send.disabled = false;
            messageInput.focus();
        }
    });

    messageInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            messageForm.requestSubmit();
        }
    });
    messageInput.addEventListener('input', () => {
        messageInput.style.height = 'auto';
        messageInput.style.height = `${Math.min(messageInput.scrollHeight, 120)}px`;
    });

    document.querySelector('#roomMenu').addEventListener('click', () => {
        document.querySelector('#roomMenuPanel').classList.toggle('hidden');
    });
    document.querySelector('#copyCode').addEventListener('click', async () => {
        if (!room) return;
        try {
            await navigator.clipboard.writeText(room.code);
            showToast('Room code copied. Send it to your people!');
        } catch {
            showToast(`Room code: ${room.code}`);
        }
    });
    document.querySelector('#copyInvite').addEventListener('click', async () => {
        if (!room) return;
        try {
            await navigator.clipboard.writeText(room.code);
            showToast('Invite code copied!');
        } catch {
            showToast(`Room code: ${room.code}`);
        }
    });
    document.querySelector('#closeRoom').addEventListener('click', async () => {
        if (!room || !window.confirm('Close this room for everyone? The room code will stop working.')) return;
        try {
            await api('close', 'POST');
            showHome();
            showToast('Your room is closed.');
        } catch (error) {
            showToast(error.message);
        }
    });
    document.querySelector('#leaveRoom').addEventListener('click', async () => {
        if (!room) return;
        try {
            await api('leave', 'POST');
        } catch (error) {
            showToast(error.message);
        }
        showHome();
    });

    if (config.startupError) showToast(config.startupError);
    if (config.membership) {
        api('messages').then((data) => {
            enterRoom({
                room: {
                    ...data.room,
                    nickname: config.membership.nickname,
                    is_admin: config.membership.is_admin,
                },
            });
        }).catch(() => showHome());
    }
})();
