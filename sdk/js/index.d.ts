// Type definitions of the Grocery Restable JavaScript SDK.

export type Row = Record<string, any>;

/** Filter value: plain value (eq), null (is.null), boolean (is.true), array (in) or operators. */
export type FilterValue =
    | string | number | boolean | null | Date | Array<string | number>
    | Partial<Record<'eq' | 'neq' | 'gt' | 'gte' | 'lt' | 'lte' | 'like' | 'ilike' | 'is', string | number | boolean | null>>
    | { in: Array<string | number> }
    | { not: Record<string, any> };

export type Filter = string | ({ [column: string]: FilterValue } & { or?: Filter[]; and?: Filter[] });

export interface SendOptions {
    method?: string;
    query?: Record<string, string | number> | Array<[string, string]>;
    body?: any;
    headers?: Record<string, string>;
    prefer?: string;
    signal?: AbortSignal;
    as?: 'json' | 'blob' | 'text';
    raw?: boolean;
    /** Do not send the user token. */
    noToken?: boolean;
    /** Do not renew an expired access token before the request. */
    noRefresh?: boolean;
}

export interface ListOptions extends Omit<SendOptions, 'query'> {
    filter?: Filter;
    /** "-created,title" (PocketBase style) or "created.desc" */
    sort?: string;
    /** Columns and embedded relations: "*,offices(city)" */
    select?: string;
    /** Full text style search (?q=) */
    search?: string;
    /** Extra query parameters */
    query?: Record<string, string | number>;
}

export interface ListResult<T = Row> {
    page: number;
    perPage: number;
    totalItems: number;
    totalPages: number;
    items: T[];
}

export class GroceryRestableError extends Error {
    status: number;
    code: string;
    details: Record<string, any>;
    url: string;
    isAbort: boolean;
}

export class AuthStore {
    token: string;
    refreshToken: string;
    user: Row | null;
    readonly isValid: boolean;
    readonly payload: Record<string, any> | null;
    save(token: string, extra?: { refreshToken?: string; user?: Row | null }): void;
    clear(): void;
    onChange(listener: (token: string, user: Row | null) => void): () => void;
}

/** Auth store persisted in localStorage and shared by the tabs of the same origin. */
export class LocalAuthStore extends AuthStore {
    constructor(key?: string);
    readonly storageKey: string;
}

export interface AuthResponse<U = Row> {
    token_type: 'Bearer';
    access_token: string;
    expires_in: number;
    expires_at: number;
    /** "jwt" strategy only */
    refresh_token?: string;
    refresh_expires_at?: number;
    user: U;
}

export interface AuthMethods {
    password: false | { identity: string[] };
    google: false | { client_id: string };
    strategy: 'jwt' | 'session';
}

export class AuthService<U = Row> {
    readonly user: U | null;
    readonly isSignedIn: boolean;
    methods(options?: SendOptions): Promise<AuthMethods>;
    login(identity: string, password: string, options?: SendOptions): Promise<AuthResponse<U>>;
    loginWithGoogle(params: { idToken?: string; credential?: string; code?: string; redirectUri?: string; codeVerifier?: string }, options?: SendOptions): Promise<AuthResponse<U>>;
    refresh(options?: SendOptions): Promise<AuthResponse<U>>;
    logout(options?: SendOptions & { all?: boolean }): Promise<true>;
    me(options?: SendOptions): Promise<U>;
}

export class CollectionService<T = Row> {
    readonly name: string;
    getList(page?: number, perPage?: number, options?: ListOptions): Promise<ListResult<T>>;
    getFullList(options?: ListOptions & { batch?: number }): Promise<T[]>;
    getFirstListItem(filter: Filter, options?: ListOptions): Promise<T>;
    getOne(id: string | number, options?: ListOptions): Promise<T>;
    create(data: Partial<T> | FormData | Row, options?: ListOptions): Promise<T>;
    createMany(rows: Array<Partial<T>>, options?: ListOptions): Promise<T[]>;
    upsert(data: Partial<T> | Array<Partial<T>>, options?: ListOptions): Promise<T | T[]>;
    update(id: string | number, data: Partial<T> | FormData | Row, options?: ListOptions): Promise<T>;
    updateMany(filter: Filter, data: Partial<T>, options?: ListOptions): Promise<T[]>;
    delete(id: string | number, options?: SendOptions): Promise<true>;
    deleteMany(filter: Filter, options?: SendOptions): Promise<Array<string | number>>;
    clone(id: string | number, overrides?: Partial<T>, options?: SendOptions): Promise<T>;
    uploadFile(id: string | number, field: string, file: Blob, options?: SendOptions): Promise<void>;
    deleteFile(id: string | number, field: string, options?: SendOptions): Promise<true>;
    getFileUrl(id: string | number, field: string, query?: Record<string, string>): string;
    getFile(id: string | number, field: string, options?: SendOptions): Promise<Blob>;
    options(field: string, query?: { search?: string; limit?: number }, options?: SendOptions): Promise<Array<{ value: any; label: string }>>;
    action(id: string | number, name: string, body?: Row, options?: SendOptions): Promise<any>;
    schema(options?: SendOptions): Promise<Row>;
}

export interface StorageObject {
    name: string;
    /** The key, e.g. "users/42/avatar.png" */
    path: string;
    size: number;
    mime: string;
    /** MD5 of the content, like S3 */
    etag: string;
    original_name: string | null;
    cache_control: string | null;
    metadata: Record<string, string>;
    owner: string | null;
    created_at: string;
    updated_at: string;
    url?: string;
}

export interface ObjectList {
    bucket: string;
    prefix: string;
    delimiter: string;
    /** Folder names relative to the prefix */
    folders: string[];
    /** Full common prefixes ("users/42/") */
    prefixes: string[];
    files: StorageObject[];
    count: number;
    truncated: boolean;
    next_cursor: string | null;
}

export class StorageService {
    readonly bucket: string;
    list(prefix?: string, options?: SendOptions & { delimiter?: '/' | ''; limit?: number; cursor?: string }): Promise<ObjectList>;
    listAll(prefix?: string, options?: SendOptions): Promise<StorageObject[]>;
    info(path: string, options?: SendOptions): Promise<StorageObject>;
    upload(path: string, file: Blob, options?: SendOptions & { name?: string; upsert?: boolean; cacheControl?: string; metadata?: Record<string, string> }): Promise<StorageObject & { replaced: boolean }>;
    download(path: string, options?: SendOptions): Promise<Blob>;
    getUrl(path: string, query?: Record<string, string>): string;
    remove(path: string, options?: SendOptions): Promise<true>;
}

export interface ClientOptions {
    /** API key sent in the "apikey" header (the anon key in browsers, never service_role). */
    apiKey?: string;
    /** User JWT sent as "Authorization: Bearer" */
    token?: string;
    headers?: Record<string, string>;
    fetch?: typeof fetch;
    authStore?: AuthStore;
}

export default class GroceryRestable {
    constructor(baseUrl?: string, options?: ClientOptions);
    baseUrl: string;
    apiKey: string;
    authStore: AuthStore;
    /** User login (/auth). */
    auth: AuthService;
    beforeSend: ((url: string, init: RequestInit) => void | { url?: string; init?: RequestInit } | Promise<void | { url?: string; init?: RequestInit }>) | null;
    afterSend: ((response: Response, data: any) => any) | null;
    collection<T = Row>(name: string): CollectionService<T>;
    from<T = Row>(name: string): CollectionService<T>;
    storage(bucket: string): StorageService;
    buckets(options?: SendOptions): Promise<Array<{ name: string; public: boolean; description: string }>>;
    rpc<R = any>(name: string, params?: Row, options?: SendOptions): Promise<R>;
    openApi(options?: SendOptions): Promise<Row>;
    setToken(token: string): this;
    clearToken(): this;
    buildUrl(path: string, query?: Record<string, string | number> | Array<[string, string]>): string;
    send<R = any>(path: string, options?: SendOptions): Promise<R>;
    tokenFresh(): boolean;
}

export { GroceryRestable };
export function filterPairs(filter?: Filter): Array<[string, string]>;
export function orderValue(sort?: string): string;
