import { describe, it, expect, beforeEach } from 'vitest';
import { useAuthStore } from '@/stores/authStore';
import type { User } from '@/types';

const mockUser: User = {
  id: 1,
  username: 'testuser',
  name: 'Test User',
  email: 'test@example.com',
  workflow_preference: 'perioperative',
  must_change_password: false,
  roles: ['admin', 'nurse'],
};

describe('authStore', () => {
  beforeEach(() => {
    // Reset the store between tests
    useAuthStore.setState({
      user: null,
      isAuthenticated: false,
    });
  });

  describe('initial state', () => {
    it('starts with null user', () => {
      const state = useAuthStore.getState();
      expect(state.user).toBeNull();
    });

    it('starts as not authenticated', () => {
      const state = useAuthStore.getState();
      expect(state.isAuthenticated).toBe(false);
    });
  });

  describe('setUser', () => {
    it('sets user and marks as authenticated', () => {
      useAuthStore.getState().setUser(mockUser);

      const state = useAuthStore.getState();
      expect(state.user).toEqual(mockUser);
      expect(state.isAuthenticated).toBe(true);
    });

    it('clears authentication when user is null', () => {
      useAuthStore.getState().setUser(mockUser);
      useAuthStore.getState().setUser(null);

      const state = useAuthStore.getState();
      expect(state.user).toBeNull();
      expect(state.isAuthenticated).toBe(false);
    });

    it('preserves all user fields', () => {
      useAuthStore.getState().setUser(mockUser);

      const user = useAuthStore.getState().user;
      expect(user?.id).toBe(1);
      expect(user?.username).toBe('testuser');
      expect(user?.name).toBe('Test User');
      expect(user?.email).toBe('test@example.com');
      expect(user?.workflow_preference).toBe('perioperative');
      expect(user?.must_change_password).toBe(false);
      expect(user?.roles).toEqual(['admin', 'nurse']);
    });
  });

  describe('logout', () => {
    it('clears user and authentication state', () => {
      useAuthStore.getState().setUser(mockUser);
      useAuthStore.getState().logout();

      const state = useAuthStore.getState();
      expect(state.user).toBeNull();
      expect(state.isAuthenticated).toBe(false);
    });
  });

  describe('hasRole', () => {
    it('returns true when user has one of the specified roles', () => {
      useAuthStore.getState().setUser(mockUser);

      expect(useAuthStore.getState().hasRole(['admin'])).toBe(true);
      expect(useAuthStore.getState().hasRole(['nurse'])).toBe(true);
    });

    it('returns true when user has any matching role', () => {
      useAuthStore.getState().setUser(mockUser);

      expect(useAuthStore.getState().hasRole(['viewer', 'admin'])).toBe(true);
    });

    it('returns false when user has none of the specified roles', () => {
      useAuthStore.getState().setUser(mockUser);

      expect(useAuthStore.getState().hasRole(['superadmin'])).toBe(false);
    });

    it('returns false when no user is set', () => {
      expect(useAuthStore.getState().hasRole(['admin'])).toBe(false);
    });

    it('returns false when user has no roles', () => {
      const userWithoutRoles: User = {
        ...mockUser,
        roles: undefined,
      };
      useAuthStore.getState().setUser(userWithoutRoles);

      expect(useAuthStore.getState().hasRole(['admin'])).toBe(false);
    });

    it('returns false for empty roles array', () => {
      useAuthStore.getState().setUser(mockUser);

      expect(useAuthStore.getState().hasRole([])).toBe(false);
    });
  });
});
