const tileColors = [
    'bg-[#1B2A45] text-[#93BBFD]',
    'bg-[#3A2A0C] text-[#FBBF24]',
    'bg-[#14322A] text-[#6EE7B7]',
    'bg-[#3B1A2A] text-[#F9A8D4]',
    'bg-[#2A2346] text-[#C4B5FD]',
];

/** A stable colour per workspace so tiles are easy to tell apart. */
export function workspaceTileColor(id: number): string {
    return tileColors[id % tileColors.length] ?? '';
}

export function workspaceInitial(name: string): string {
    return name.trim().charAt(0).toUpperCase() || '?';
}
