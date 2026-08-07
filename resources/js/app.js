import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

/**
 * Theme handling.
 *
 * The `dark` class is applied by a blocking <script> in the layout <head> so
 * there is no flash of the wrong theme; this store only mirrors and mutates it.
 */
Alpine.store('theme', {
    dark: document.documentElement.classList.contains('dark'),

    toggle() {
        this.dark = !this.dark;
        document.documentElement.classList.toggle('dark', this.dark);
        localStorage.setItem('theme', this.dark ? 'dark' : 'light');
    },
});

/**
 * Transient toast notifications, driven from Blade via
 * `$dispatch('notify', { type, message })` or `window.notify(...)`.
 */
Alpine.store('toasts', {
    items: [],
    seq: 0,

    push(type, message, timeout = 4500) {
        const id = ++this.seq;
        this.items.push({ id, type, message });
        setTimeout(() => this.dismiss(id), timeout);
    },

    dismiss(id) {
        this.items = this.items.filter((t) => t.id !== id);
    },
});

window.notify = (type, message) => Alpine.store('toasts').push(type, message);

Alpine.start();
