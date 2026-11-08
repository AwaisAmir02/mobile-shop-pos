import './bootstrap';

document.addEventListener('alpine:init', () => {
    Alpine.store('toasts', {
        items: [],

        push(toast) {
            const id = `${Date.now()}-${Math.random()}`;

            this.items.push({
                id,
                type: toast.type ?? 'success',
                message: toast.message ?? '',
            });

            setTimeout(() => this.remove(id), 4000);
        },

        remove(id) {
            this.items = this.items.filter((item) => item.id !== id);
        },
    });

    // The styled confirmation that replaces wire:confirm (x-ui.confirm-dialog,
    // App\Support\ConfirmAction). ask() resolves true or false.
    Alpine.store('confirm', {
        open: false,
        title: '',
        message: '',
        confirmLabel: 'Confirm',
        danger: false,
        resolver: null,

        ask({ title = 'Are you sure?', message = '', confirmLabel = 'Confirm', danger = false } = {}) {
            // A second ask while one is open answers the first with "no".
            this.settle(false);
            Object.assign(this, { title, message, confirmLabel, danger, open: true });

            return new Promise((resolve) => {
                this.resolver = resolve;
            });
        },

        settle(answer) {
            const resolve = this.resolver;
            this.resolver = null;
            this.open = false;
            if (resolve) resolve(answer);
        },
    });

    // Quick date ranges over a screen's existing from/to properties
    // (x-ui.date-range). Dates are the browser's local calendar day, the same
    // day the date boxes show.
    Alpine.data('dateRange', (fromProperty, toProperty) => ({
        ymd(date) {
            const pad = (n) => String(n).padStart(2, '0');
            return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
        },

        ranges() {
            const today = new Date();
            const day = (offset) => new Date(today.getFullYear(), today.getMonth(), today.getDate() + offset);

            return {
                today: [this.ymd(today), this.ymd(today)],
                yesterday: [this.ymd(day(-1)), this.ymd(day(-1))],
                week: [this.ymd(day(-6)), this.ymd(today)],
                month: [this.ymd(new Date(today.getFullYear(), today.getMonth(), 1)), this.ymd(today)],
                lastMonth: [
                    this.ymd(new Date(today.getFullYear(), today.getMonth() - 1, 1)),
                    this.ymd(new Date(today.getFullYear(), today.getMonth(), 0)),
                ],
            };
        },

        get preset() {
            const from = this.$wire[fromProperty] || '';
            const to = this.$wire[toProperty] || '';
            if (! from && ! to) return 'all';
            const match = Object.entries(this.ranges()).find(([, [a, b]]) => a === from && b === to);

            return match ? match[0] : 'custom';
        },

        choose(key) {
            if (key === 'custom') return;
            const [from, to] = key === 'all' ? ['', ''] : this.ranges()[key];
            this.$wire.$set(fromProperty, from, false);
            this.$wire.$set(toProperty, to);
        },
    }));
});

document.addEventListener('livewire:init', () => {
    Livewire.on('toast', (payload) => {
        Alpine.store('toasts').push(Array.isArray(payload) ? payload[0] : payload);
    });
});
