/** Gives a field one short shake (see .so-shake in app.css) and focuses it. */
export function shake(field: HTMLElement | null | undefined): void {
    if (!field) return;
    field.classList.remove('so-shake');
    void field.offsetWidth; // restart the animation
    field.classList.add('so-shake');
    field.focus();
}
