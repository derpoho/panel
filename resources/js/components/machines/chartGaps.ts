/**
 * Fill short runs of missing readings by drawing straight across them, so a
 * report that lands just past a bucket edge doesn't break the line. Longer
 * runs stay empty: the machine wasn't reporting.
 */
export function bridgeGaps(
    values: (number | null)[],
    longest = 2,
): (number | null)[] {
    const result = [...values];
    let start = -1;

    values.forEach((value, index) => {
        if (value === null) {
            if (start === -1) start = index;
            return;
        }
        if (start > 0 && index - start <= longest) {
            const before = values[start - 1]!;
            const span = index - start + 1;
            for (let gap = start; gap < index; gap++) {
                result[gap] =
                    before + ((value - before) * (gap - start + 1)) / span;
            }
        }
        start = -1;
    });

    return result;
}
