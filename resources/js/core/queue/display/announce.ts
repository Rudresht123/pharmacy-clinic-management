/**
 * Saying a token out loud, in the languages the clinic chose.
 *
 * The browser's own speech voices, so nothing is installed and no text leaves
 * the building. Two doctors calling at the same instant are read one after the
 * other, never over each other, which is the whole reason this keeps a line.
 *
 * Browsers refuse to make a sound until somebody has touched the page, so the
 * television asks for one tap, and chimes at once so whoever tapped knows it
 * worked. Nothing here is required for the screen to work: where a voice is
 * missing, the chime and the picture still do the job.
 */

export interface Announcement {
    token: string;
    room: string | null;
    doctorName: string | null;
    patientName: string | null;
    recalled: boolean;
}

/** More than this waiting means the room has moved on from the oldest. */
const LONGEST_LINE = 6;

const line: Announcement[] = [];
let speaking = false;

const DIGITS: Record<'en' | 'hi', string[]> = {
    en: ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine'],
    hi: ['शून्य', 'एक', 'दो', 'तीन', 'चार', 'पांच', 'छह', 'सात', 'आठ', 'नौ'],
};

/**
 * The whole token, exactly as it is printed: "A001" is "A zero zero one". Spelled
 * out digit by digit, because a voice left to itself reads it as a word or drops
 * the zeros, and the patient is looking at a slip that says A001.
 */
function spoken(token: string, language: 'en' | 'hi'): string {
    return [...token].map((character) => (/\d/.test(character) ? DIGITS[language][Number(character)] : character)).join(' ');
}

function sentence(item: Announcement, language: 'en' | 'hi'): string {
    const token = spoken(item.token, language);
    const who = item.patientName ? `${item.patientName}, ` : '';

    if (language === 'hi') {
        const place = item.room
            ? `कमरा नंबर ${item.room} में`
            : item.doctorName
              ? `${item.doctorName} के कमरे में`
              : 'डॉक्टर के कमरे में';

        return item.recalled
            ? `टोकन ${token}, दोबारा बुलाया जा रहा है। ${who}कृपया ${place} जाएं।`
            : `टोकन ${token}। ${who}कृपया ${place} जाएं।`;
    }

    const place = item.room
        ? `room number ${item.room}`
        : item.doctorName
          ? `${item.doctorName}'s room`
          : "the doctor's room";

    return item.recalled
        ? `Calling again, token ${token}. ${who}please go to ${place}.`
        : `Token ${token}. ${who}please go to ${place}.`;
}

function voiceFor(language: 'en' | 'hi'): SpeechSynthesisVoice | undefined {
    const voices = window.speechSynthesis.getVoices();
    const wanted = language === 'hi' ? ['hi-IN', 'hi'] : ['en-IN', 'en-GB', 'en-US', 'en'];

    for (const lang of wanted) {
        const found = voices.find((voice) => voice.lang.toLowerCase().startsWith(lang.toLowerCase()));

        if (found) {
            return found;
        }
    }

    return undefined;
}

function say(text: string, language: 'en' | 'hi'): Promise<void> {
    return new Promise((resolve) => {
        const utterance = new SpeechSynthesisUtterance(text);

        utterance.lang = language === 'hi' ? 'hi-IN' : 'en-IN';
        utterance.rate = 0.92;

        const voice = voiceFor(language);

        if (voice) {
            utterance.voice = voice;
        }

        // A browser that never finishes must not silence every call after it.
        // Long enough for a spelled-out token and a name at this pace.
        const giveUp = window.setTimeout(resolve, 16000);
        const done = () => {
            window.clearTimeout(giveUp);
            resolve();
        };

        utterance.onend = done;
        utterance.onerror = done;

        window.speechSynthesis.speak(utterance);
    });
}

/** Two soft notes, loud enough for a waiting room to look up. */
function chime(context: AudioContext): Promise<void> {
    // A screen left alone for hours can have its audio suspended by the browser.
    void context.resume();

    const start = context.currentTime;

    [880, 660].forEach((frequency, index) => {
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        const at = start + index * 0.28;

        oscillator.type = 'sine';
        oscillator.frequency.value = frequency;
        oscillator.connect(gain);
        gain.connect(context.destination);

        gain.gain.setValueAtTime(0.0001, at);
        gain.gain.exponentialRampToValueAtTime(0.35, at + 0.04);
        gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.5);

        oscillator.start(at);
        oscillator.stop(at + 0.55);
    });

    return new Promise((resolve) => window.setTimeout(resolve, 850));
}

let audio: AudioContext | null = null;

/** Called from the tap: the one moment a browser allows sound to begin. */
export function unlockSound(): void {
    try {
        audio ??= new AudioContext();
        void chime(audio);

        // An empty utterance is what unlocks speech on Chrome. Asking for the
        // voices now means they have loaded by the time the first call comes.
        window.speechSynthesis?.getVoices();
        window.speechSynthesis?.speak(new SpeechSynthesisUtterance(''));
    } catch {
        // No audio on this device. The picture still works.
    }
}

async function work(languages: ('en' | 'hi')[]): Promise<void> {
    if (speaking) {
        return;
    }

    speaking = true;

    try {
        while (line.length > 0) {
            const item = line.shift() as Announcement;

            if (audio) {
                await chime(audio);
            }

            if ('speechSynthesis' in window) {
                for (const language of languages) {
                    await say(sentence(item, language), language);
                }
            }
        }
    } catch {
        line.length = 0;
    } finally {
        speaking = false;
    }
}

export function announce(item: Announcement, languages: ('en' | 'hi')[]): void {
    line.push(item);

    while (line.length > LONGEST_LINE) {
        line.shift();
    }

    void work(languages.length > 0 ? languages : ['en']);
}
