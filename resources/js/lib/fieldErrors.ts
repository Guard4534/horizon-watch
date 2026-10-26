export function errorId(field: string): string {
    return `${field}-error`;
}

export function invalidField(
    field: string,
    message?: string | null,
): { 'aria-invalid'?: true; 'aria-describedby'?: string } {
    return message
        ? { 'aria-invalid': true, 'aria-describedby': errorId(field) }
        : {};
}
