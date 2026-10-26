export function roleTagClass(role: App.Enums.TeamRole): string {
    switch (role) {
        case 'owner':
        case 'admin':
            return 'nc-tag nc-tag-accent';
        case 'member':
            return 'nc-tag nc-tag-neutral';
        case 'viewer':
            return 'nc-tag nc-tag-outline';
    }
}
