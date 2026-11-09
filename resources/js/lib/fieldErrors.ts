import { trans } from 'laravel-vue-i18n';
import { shake } from '@/lib/shake';

/**
 * The browser's own "Please fill in this field" bubbles, everywhere in the
 * panel (dialogs included), swapped for the sign-in pages' style: the field
 * outlined red, the first one given a shake, and a plain message under it.
 * The message goes as soon as they start fixing the field.
 */

type Field = HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement;

function message(field: Field): string {
    const v = field.validity;
    const input = field as HTMLInputElement;

    if (v.valueMissing) {
        if (input.type === 'checkbox') return trans('Tick this to carry on.');
        if (field instanceof HTMLSelectElement) return trans('Choose one.');
        return trans('Fill this in.');
    }
    if (v.typeMismatch) {
        if (input.type === 'email')
            return trans('That doesn’t look like an email address.');
        if (input.type === 'url')
            return trans('Enter a full address, like https://example.com.');
    }
    if (v.tooShort)
        return trans('Use at least :minLength characters.', {
            minLength: String(input.minLength),
        });
    if (v.tooLong)
        return trans('Use :maxLength characters or fewer.', {
            maxLength: String(input.maxLength),
        });
    if (v.rangeUnderflow)
        return trans('Use :min or more.', { min: String(input.min) });
    if (v.rangeOverflow)
        return trans('Use :max or less.', { max: String(input.max) });
    if (v.stepMismatch) return trans('Use a whole number.');
    if (v.patternMismatch)
        return field.title || trans('That isn’t in the right format.');

    return field.validationMessage;
}

/** What gets the red outline: the field, or the box it sits in. */
function frame(field: Field): HTMLElement {
    const parent = field.parentElement;
    return parent && /focus-within/.test(parent.className) ? parent : field;
}

function errorFor(field: Field): HTMLElement | null {
    const next = frame(field).nextElementSibling;
    return next instanceof HTMLElement && next.dataset.fieldError !== undefined
        ? next
        : null;
}

function show(field: Field): void {
    const box = frame(field);
    box.dataset.invalid = 'true';
    field.setAttribute('aria-invalid', 'true');

    let note = errorFor(field);
    if (!note) {
        note = document.createElement('p');
        note.dataset.fieldError = '';
        note.className = 'so-field-error';
        note.setAttribute('role', 'alert');
        box.insertAdjacentElement('afterend', note);
    }
    note.textContent = message(field);
}

function clear(field: Field): void {
    const box = frame(field);
    if (box.dataset.invalid === undefined) return;

    delete box.dataset.invalid;
    field.removeAttribute('aria-invalid');
    errorFor(field)?.remove();
}

let lastShake = 0;

export function installFieldErrors(): void {
    document.addEventListener(
        'invalid',
        (event) => {
            const field = event.target;
            if (
                !(field instanceof HTMLInputElement) &&
                !(field instanceof HTMLTextAreaElement) &&
                !(field instanceof HTMLSelectElement)
            ) {
                return;
            }

            event.preventDefault();
            show(field);

            // One submit fires this for every bad field: shake the first.
            const now = Date.now();
            if (now - lastShake > 300) {
                lastShake = now;
                shake(frame(field));
                field.focus();
            }
        },
        true,
    );

    const fix = (event: Event) => {
        const field = event.target;
        if (
            (field instanceof HTMLInputElement ||
                field instanceof HTMLTextAreaElement ||
                field instanceof HTMLSelectElement) &&
            field.validity.valid
        ) {
            clear(field);
        }
    };
    document.addEventListener('input', fix, true);
    document.addEventListener('change', fix, true);
}
