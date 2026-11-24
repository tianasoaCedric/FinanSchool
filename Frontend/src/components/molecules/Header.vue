<template>
  <header class="hd">
    <div class="hd-left">
      <div class="hd-search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input
          v-model="searchQuery"
          type="search"
          placeholder="Rechercher…"
          aria-label="Rechercher"
          class="hd-search-input"
        />
      </div>
    </div>

    <div class="hd-right">
      <!-- Mode clair / sombre -->
      <UiButton
        variant="ghost"
        icon-only
        :icon="theme === 'dark' ? 'bi-sun' : 'bi-moon-stars'"
        :title="theme === 'dark' ? 'Passer en mode clair' : 'Passer en mode sombre'"
        :aria-label="theme === 'dark' ? 'Passer en mode clair' : 'Passer en mode sombre'"
        @click="toggleTheme"
      />

      <!-- Notifications -->
      <div class="hd-menu">
        <UiButton
          variant="ghost"
          icon-only
          icon="bi-bell"
          class="hd-notif-btn"
          aria-label="Notifications"
          :aria-expanded="showNotifications"
          @click="showNotifications = !showNotifications; showProfile = false"
        >
          <span v-if="unreadCount > 0" class="hd-badge">{{ unreadCount }}</span>
        </UiButton>

        <div v-show="showNotifications" class="hd-dropdown hd-dropdown--notif">
          <div class="hd-dropdown-head">
            <h3>Notifications</h3>
            <UiButton v-if="unreadCount > 0" variant="link" size="sm" @click="markAllAsRead">Tout marquer comme lu</UiButton>
          </div>
          <div class="hd-notif-list">
            <p v-if="notifications.length === 0" class="hd-empty">
              <i class="bi bi-bell-slash"></i> Aucune notification
            </p>
            <div
              v-for="notification in notifications"
              :key="notification.id"
              class="hd-notif"
              :class="{ 'is-unread': notification.statut !== 'lu' }"
              @click="handleNotificationClick(notification)"
            >
              <div class="hd-notif-icon" :style="getNotificationStyle(notification)">
                <i :class="['bi', getNotificationIcon(notification)]"></i>
              </div>
              <div class="hd-notif-body">
                <p class="hd-notif-title">{{ notification.titre || notification.title }}</p>
                <p class="hd-notif-msg">{{ notification.message }}</p>
                <p class="hd-notif-date">{{ formatDate(notification.created_at) }}</p>
              </div>
              <UiButton
                variant="ghost"
                size="sm"
                icon-only
                icon="bi-x-lg"
                class="hd-notif-close"
                aria-label="Retirer de la liste"
                title="Retirer de la liste"
                @click.stop="removeFromList(notification.id)"
              />
            </div>
          </div>
        </div>
      </div>

      <!-- Profil -->
      <div class="hd-menu">
        <button
          type="button"
          class="hd-profile"
          :aria-expanded="showProfile"
          @click="showProfile = !showProfile; showNotifications = false"
        >
          <span class="hd-avatar">{{ getInitials(user?.name || 'U') }}</span>
          <span v-if="user?.name" class="hd-profile-name">{{ user.name }}</span>
          <i class="bi bi-chevron-down hd-profile-chevron"></i>
        </button>

        <div v-show="showProfile" class="hd-dropdown hd-dropdown--profile">
          <div class="hd-profile-card">
            <span class="hd-avatar hd-avatar--lg">{{ getInitials(user?.name || 'U') }}</span>
            <div>
              <p class="hd-profile-card-name">{{ user?.name || 'Utilisateur' }}</p>
              <p class="hd-profile-card-mail">{{ user?.email || '' }}</p>
            </div>
          </div>
          <button type="button" class="hd-menu-item hd-menu-item--danger" @click="handleLogout">
            <i class="bi bi-box-arrow-right"></i> Déconnexion
          </button>
        </div>
      </div>
    </div>
  </header>
</template>

<script>
import { API_BASE } from '@/config/api';
import axios from "axios"
import { useTheme } from '@/composables/useTheme';

export default {
  name: 'AppHeader',
  props: {
    user: {
      type: Object,
      default: () => ({ name: 'Utilisateur', email: 'email@example.com' })
    }
  },
  setup() {
    const { theme, toggleTheme } = useTheme();
    return { theme, toggleTheme };
  },
  data() {
    return {
      searchQuery: '',
      showNotifications: false,
      showProfile: false,
      notifications: []
    }
  },
  computed: {
    unreadCount() {
      return this.notifications.filter(n => n.statut !== 'lu').length
    }
  },
  mounted() {
    this.fetchNotifications()
    document.addEventListener('click', this.onDocumentClick)
  },
  beforeUnmount() {
    document.removeEventListener('click', this.onDocumentClick)
  },
  methods: {
    // Ferme les menus déroulants quand on clique ailleurs
    onDocumentClick(event) {
      if (!this.$el.contains(event.target)) {
        this.showNotifications = false
        this.showProfile = false
      }
    },
    getInitials(name) {
      return name
        ? name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
        : 'U'
    },
    async fetchNotifications() {
      try {
        const { data } = await axios.get(`${API_BASE}/notifications`)
        this.notifications = data
      } catch (err) {
        // Optionnel : toasts ou erreur console
      }
    },
    getNotificationIcon(notification) {
      return notification.niveau_urgence?.icone || 'bi-info-circle-fill'
    },
    getNotificationStyle(notification) {
      return {
        background: notification.niveau_urgence?.couleur || '#dbeafe',
        color: '#1e293b'
      }
    },
    formatDate(dateString) {
      return new Date(dateString).toLocaleString('fr-FR')
    },
    async markAsRead(id) {
      const notif = this.notifications.find(n => n.id === id)
      if (notif && notif.statut !== 'lu') {
        try {
          await axios.patch(`${API_BASE}/notifications/${id}/read`)
          notif.statut = 'lu'
        } catch (err) {
          // Optionnel : toast/alerte
        }
      }
    },
    async markAllAsRead() {
      const unreadIds = this.notifications.filter(n => n.statut !== 'lu').map(n => n.id)
      await Promise.all(unreadIds.map(id => this.markAsRead(id)))
    },
    async handleNotificationClick(notification) {
      await this.markAsRead(notification.id)
      const url = notification.redirect_url ||
                  notification.lien_redirection ||
                  notification.evenement?.donnees_evenement?.lien_redirection
      if (url) {
        if (this.$router && url.startsWith('/')) {
          this.$router.push(url)
        } else {
          window.location.href = url
        }
      }
    },
    handleLogout() {
      localStorage.removeItem("token");
      this.$router.push("/")
    },
    removeFromList(id) {
      this.notifications = this.notifications.filter(n => n.id !== id)
    }
  }
}
</script>

<style scoped>
/* Les couleurs suivent le thème (.fs-page) ; valeurs de repli pour les pages
   qui n'utilisent pas encore AppLayout. */
.hd {
  --hd-bg: var(--fs-surface, #ffffff);
  --hd-bg-2: var(--fs-surface-2, #f8fafc);
  --hd-border: var(--fs-border, #e5e8ef);
  --hd-text: var(--fs-text, #0f172a);
  --hd-text-2: var(--fs-text-2, #334155);
  --hd-muted: var(--fs-muted, #64748b);
  --hd-primary: var(--fs-primary, #1c45bd);
  --hd-primary-soft: var(--fs-primary-soft, rgba(28, 69, 189, 0.08));

  position: sticky;
  top: 0;
  z-index: 100;
  margin-left: 278px;
  height: 72px;
  padding: 0 32px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  background: color-mix(in srgb, var(--hd-bg) 88%, transparent);
  backdrop-filter: saturate(1.4) blur(10px);
  border-bottom: 1px solid var(--hd-border);
  font-family: 'Inter Variable', 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
  color: var(--hd-text);
}

.hd-left { flex: 1; min-width: 0; }
.hd-search { position: relative; max-width: 380px; }
.hd-search .bi-search {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--hd-muted);
  font-size: 14px;
  pointer-events: none;
}
.hd-search-input {
  width: 100%;
  height: 40px;
  padding: 0 12px 0 36px;
  border: 1px solid var(--hd-border);
  border-radius: 10px;
  background: var(--hd-bg-2);
  color: var(--hd-text);
  font: 400 14px inherit;
  font-family: inherit;
  outline: none;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
}
.hd-search-input::placeholder { color: var(--hd-muted); }
.hd-search-input:focus { border-color: var(--hd-primary); background: var(--hd-bg); box-shadow: 0 0 0 3px var(--hd-primary-soft); }

.hd-right { display: flex; align-items: center; gap: 8px; }
.hd-menu { position: relative; }

.hd-profile:focus-visible { outline: 2px solid var(--hd-primary); outline-offset: 2px; }
.hd-notif-btn { position: relative; font-size: 17px; }
.hd-badge {
  position: absolute;
  top: 4px;
  right: 4px;
  min-width: 17px;
  height: 17px;
  padding: 0 4px;
  border-radius: 999px;
  background: #ef4444;
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  line-height: 17px;
  text-align: center;
  box-shadow: 0 0 0 2px var(--hd-bg);
}

.hd-profile {
  display: flex;
  align-items: center;
  gap: 10px;
  height: 44px;
  padding: 0 10px 0 4px;
  margin-left: 6px;
  border: 1px solid var(--hd-border);
  border-radius: 999px;
  background: var(--hd-bg);
  color: var(--hd-text);
  font: 500 14px inherit;
  font-family: inherit;
  cursor: pointer;
  transition: background-color 0.15s ease;
}
.hd-profile:hover { background: var(--hd-bg-2); }
.hd-profile-chevron { font-size: 11px; color: var(--hd-muted); }
.hd-avatar {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: linear-gradient(135deg, #1c45bd, #5b7fff);
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.02em;
}
.hd-avatar--lg { width: 44px; height: 44px; font-size: 15px; }

.hd-dropdown {
  position: absolute;
  top: calc(100% + 10px);
  right: 0;
  z-index: 1000;
  background: var(--hd-bg);
  border: 1px solid var(--hd-border);
  border-radius: 14px;
  box-shadow: 0 20px 40px -16px rgba(15, 23, 42, 0.3);
  overflow: hidden;
}
.hd-dropdown--notif { width: 380px; }
.hd-dropdown--profile { width: 280px; padding: 8px; }

.hd-dropdown-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px;
  border-bottom: 1px solid var(--hd-border);
}
.hd-dropdown-head h3 { margin: 0; font-size: 15px; font-weight: 600; color: var(--hd-text); }

.hd-notif-list { max-height: 420px; overflow-y: auto; }
.hd-empty { margin: 0; padding: 32px 16px; text-align: center; color: var(--hd-muted); font-size: 14px; }
.hd-empty .bi { display: block; font-size: 22px; margin-bottom: 6px; }
.hd-notif {
  position: relative;
  display: flex;
  gap: 12px;
  padding: 12px 36px 12px 16px;
  border-bottom: 1px solid var(--hd-border);
  cursor: pointer;
  transition: background-color 0.15s ease;
}
.hd-notif:last-child { border-bottom: 0; }
.hd-notif:hover { background: var(--hd-bg-2); }
.hd-notif.is-unread { background: var(--hd-primary-soft); }
.hd-notif.is-unread::before {
  content: "";
  position: absolute;
  left: 6px;
  top: 50%;
  width: 6px;
  height: 6px;
  margin-top: -3px;
  border-radius: 50%;
  background: var(--hd-primary);
}
.hd-notif-icon { flex: none; width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center; font-size: 15px; }
.hd-notif-body { min-width: 0; }
.hd-notif-title { margin: 0 0 2px; font-size: 13.5px; font-weight: 600; color: var(--hd-text); }
.hd-notif-msg { margin: 0 0 4px; font-size: 13px; color: var(--hd-text-2); line-height: 1.4; }
.hd-notif-date { margin: 0; font-size: 12px; color: var(--hd-muted); }
/* Le style visuel vient de UiButton ; ici seulement la position et l'apparition au survol */
.hd-notif-close { position: absolute; top: 6px; right: 6px; opacity: 0; }
.hd-notif:hover .hd-notif-close, .hd-notif-close:focus-visible { opacity: 1; }

.hd-profile-card { display: flex; align-items: center; gap: 12px; padding: 10px 10px 14px; border-bottom: 1px solid var(--hd-border); margin-bottom: 6px; }
.hd-profile-card-name { margin: 0; font-size: 14px; font-weight: 600; color: var(--hd-text); }
.hd-profile-card-mail { margin: 2px 0 0; font-size: 12.5px; color: var(--hd-muted); word-break: break-all; }
.hd-menu-item {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 10px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: var(--hd-text-2);
  font: 500 14px inherit;
  font-family: inherit;
  text-align: left;
  cursor: pointer;
}
.hd-menu-item:hover { background: var(--hd-bg-2); }
.hd-menu-item--danger { color: #dc2626; }
.hd-menu-item--danger:hover { background: rgba(220, 38, 38, 0.08); }

@media (max-width: 768px) {
  .hd { margin-left: 0; padding: 0 16px; }
  .hd-profile-name { display: none; }
  .hd-dropdown--notif { width: calc(100vw - 32px); right: -60px; }
}
</style>
