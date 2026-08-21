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
});

document.addEventListener('livewire:init', () => {
    Livewire.on('toast', (payload) => {
        Alpine.store('toasts').push(Array.isArray(payload) ? payload[0] : payload);
    });
});
