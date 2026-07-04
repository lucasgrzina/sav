export interface Permission {
    guid: string
    name: string
}

export interface PermissionGroup {
    module: string
    permissions: Permission[]
}
