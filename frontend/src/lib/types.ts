export interface User {
  id: number;
  name: string;
  email: string;
  locale: string;
  is_active: boolean;
  roles: string[];
  permissions: string[];
}
