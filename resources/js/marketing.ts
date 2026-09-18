import {
    Alpine,
    Livewire,
} from '../../vendor/livewire/livewire/dist/livewire.esm.js';

declare global {
    interface Window {
        Alpine: typeof Alpine;
    }
}

window.Alpine = Alpine;

Livewire.start();
