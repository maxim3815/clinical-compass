export type UserRole = 'doctor' | 'patient';

export type User = {
    id: number;
    name: string;
    login: string;
    role: UserRole;
};

export type Auth = {
    user: User;
};
