/**
 * Звук алерта у врача: три коротких сигнала Web Audio, без звукового файла.
 *
 * Браузеры не дают странице играть звук, пока на ней ни разу не нажали (автозвук). Поэтому
 * звук создаётся только после первого нажатия или клавиши на странице, а до него алерт
 * приходит без звука: красная плашка видна в любом случае.
 */
let context: AudioContext | null = null;

function audioContext(): AudioContext | null {
    if (
        context === null &&
        typeof window !== 'undefined' &&
        'AudioContext' in window &&
        navigator.userActivation?.hasBeenActive !== false
    ) {
        context = new AudioContext();
    }

    return context;
}

/**
 * Вызывается при нажатии на странице: дальше звук алерта проигрывается без нажатия.
 */
export function unlockAlertSound(): void {
    const sound = audioContext();

    if (sound?.state === 'suspended') {
        sound.resume().catch(() => undefined);
    }
}

export function playAlertSound(): void {
    try {
        const sound = audioContext();

        if (sound === null) {
            return;
        }

        if (sound.state === 'suspended') {
            sound.resume().catch(() => undefined);
        }

        const start = sound.currentTime + 0.05;

        for (let beep = 0; beep < 3; beep++) {
            const at = start + beep * 0.3;
            const tone = sound.createOscillator();
            const volume = sound.createGain();

            tone.type = 'sine';
            tone.frequency.value = 880;
            volume.gain.setValueAtTime(0.0001, at);
            volume.gain.exponentialRampToValueAtTime(0.3, at + 0.02);
            volume.gain.exponentialRampToValueAtTime(0.0001, at + 0.2);
            tone.connect(volume).connect(sound.destination);
            tone.start(at);
            tone.stop(at + 0.22);
        }
    } catch {
        // Без звука плашка всё равно на экране.
    }
}
