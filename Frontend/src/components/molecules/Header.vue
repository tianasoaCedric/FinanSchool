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
          :class="{ 'is-ringing': cloche }"
          :aria-label="nonLues ? `Notifications, ${nonLues} non lue(s)` : 'Notifications'"
          :aria-expanded="showNotifications"
          @click="basculerNotifications"
        >
          <span v-if="nonLues > 0" class="hd-badge" :class="{ 'hd-badge--urgent': urgentes > 0 }">{{ nonLues > 99 ? '99+' : nonLues }}</span>
        </UiButton>

        <div v-show="showNotifications" class="hd-dropdown hd-dropdown--notif" role="dialog" aria-label="Notifications">
          <div class="hd-notif-head">
            <div>
              <h3>Notifications</h3>
              <p>{{ nonLues ? `${nonLues} non lue(s)` : 'Tout est lu' }}</p>
            </div>
            <UiButton v-if="nonLues > 0" variant="ghost" size="sm" icon="bi-check2-all" @click="toutLireMenu">Tout lire</UiButton>
          </div>

          <div class="hd-notif-tabs" role="tablist">
            <button type="button" role="tab" :aria-selected="ongletNotif === 'tous'" :class="{ 'is-active': ongletNotif === 'tous' }" @click="ongletNotif = 'tous'">Toutes</button>
            <button type="button" role="tab" :aria-selected="ongletNotif === 'non_lu'" :class="{ 'is-active': ongletNotif === 'non_lu' }" @click="ongletNotif = 'non_lu'">
              Non lues <span v-if="nonLues">{{ nonLues }}</span>
            </button>
          </div>

          <div class="hd-notif-list">
            <div v-if="!notifEtat.charge" class="hd-notif-loading"><UiSkeleton :rows="3" /></div>
            <div v-else-if="!groupesNotif.length" class="hd-notif-empty">
              <span><i :class="['bi', ongletNotif === 'non_lu' ? 'bi-check2-circle' : 'bi-bell-slash']"></i></span>
              <p>{{ ongletNotif === 'non_lu' ? 'Vous êtes à jour' : 'Aucune notification' }}</p>
              <small>{{ ongletNotif === 'non_lu' ? 'Aucune notification non lue.' : 'Les imports, alertes de clôture et contrôles apparaîtront ici.' }}</small>
            </div>
            <template v-else>
              <section v-for="g in groupesNotif" :key="g.titre" class="hd-notif-group">
                <h4>{{ g.titre }}</h4>
                <NotificationItem
                  v-for="n in g.items"
                  :key="n.id"
                  :n="n"
                  compact
                  @ouvert="showNotifications = false"
                  @supprimer="supprimerNotif"
                />
              </section>
            </template>
          </div>

          <router-link to="/notifications" class="hd-notif-foot" @click="showNotifications = false">
            Voir toutes les notifications <i class="bi bi-arrow-right"></i>
          </router-link>
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
          <router-link :to="{ path: '/reglages', query: { section: 'profil' } }" class="hd-menu-item" @click="showProfile = false">
            <i class="bi bi-person-circle"></i> Mon profil
          </router-link>
          <router-link to="/reglages" class="hd-menu-item" @click="showProfile = false">
            <i class="bi bi-gear"></i> Paramètres
          </router-link>
          <button type="button" class="hd-menu-item hd-menu-item--danger" @click="handleLogout">
            <i class="bi bi-box-arrow-right"></i> Déconnexion
          </button>
        </div>
      </div>
    </div>
  </header>
</template>

<script>
import { useTheme } from '@/composables/useTheme';
import { grouper, useCentreNotifications } from '@/composables/useCentreNotifications';
import NotificationItem from '@/components/molecules/notifications/NotificationItem.vue';

export default {
  name: 'AppHeader',
  props: {
    user: {
      type: Object,
      default: () => ({ name: 'Utilisateur', email: 'email@example.com' })
    }
  },
  components: { NotificationItem },
  setup() {
    const { theme, toggleTheme } = useTheme();
    const centre = useCentreNotifications();
    return { theme, toggleTheme, centre, notifEtat: centre.etat, nonLues: centre.nonLues, urgentes: centre.urgentes };
  },
  data() {
    return {
      searchQuery: '',
      showNotifications: false,
      showProfile: false,
      ongletNotif: 'tous',
      cloche: false,
    }
  },
  computed: {
    groupesNotif() {
      const liste = this.notifEtat.recentes.filter(n => this.ongletNotif === 'tous' || n.statut !== 'lu')
      return grouper(liste)
    }
  },
  watch: {
    // Petite animation de la cloche à l'arrivée d'une notification
    'notifEtat.nouvelles'(n, avant) {
      if (n > avant) {
        this.cloche = true
        setTimeout(() => { this.cloche = false }, 1200)
      }
    }
  },
  mounted() {
    this.centre.demarrer()
    document.addEventListener('click', this.onDocumentClick)
    document.addEventListener('keydown', this.onKeydown)
  },
  beforeUnmount() {
    this.centre.arreter()
    document.removeEventListener('click', this.onDocumentClick)
    document.removeEventListener('keydown', this.onKeydown)
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
    onKeydown(event) {
      if (event.key === 'Escape') {
        this.showNotifications = false
        this.showProfile = false
      }
    },
    basculerNotifications() {
      this.showNotifications = !this.showNotifications
      this.showProfile = false
      if (this.showNotifications) {
        this.centre.rafraichir()
        this.notifEtat.nouvelles = 0
      }
    },
    async toutLireMenu() {
      await this.centre.toutLire()
    },
    async supprimerNotif(n) {
      await this.centre.supprimer(n)
    },
    handleLogout() {
      localStorage.removeItem("token");
      this.$router.push("/")
    },
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
.hd-badge--urgent { background: var(--fs-negative, #dc2626) !important; }
.hd-badge {
  position: absolute;
  top: 4px;
  right: 4px;
  min-width: 17px;
  height: 17px;
  padding: 0 4px;
  border-radius: 999px;
  background: var(--fs-primary, #1c45bd);
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
.hd-dropdown--notif { width: 400px; display: flex; flex-direction: column; max-height: min(620px, calc(100vh - 100px)); }
.hd-dropdown--profile { width: 280px; padding: 8px; }

/* Notifications */
.hd-notif-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 16px 10px; }
.hd-notif-head h3 { margin: 0; font-size: 15px; font-weight: 600; color: var(--hd-text); }
.hd-notif-head p { margin: 2px 0 0; font-size: 12px; color: var(--hd-muted); }
.hd-notif-tabs { display: flex; gap: 16px; padding: 0 16px; border-bottom: 1px solid var(--hd-border); }
.hd-notif-tabs button {
  position: relative; display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 0 10px; border: 0; background: none; cursor: pointer;
  font: 500 13px inherit; font-family: inherit; color: var(--hd-muted);
}
.hd-notif-tabs button span {
  min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; line-height: 18px; text-align: center;
  background: var(--hd-primary-soft); color: var(--fs-primary-text, #1c45bd); font-size: 11px; font-weight: 600;
}
.hd-notif-tabs button.is-active { color: var(--hd-text); }
.hd-notif-tabs button.is-active::after {
  content: ""; position: absolute; left: 0; right: 0; bottom: -1px; height: 2px; border-radius: 2px; background: var(--hd-primary);
}
.hd-notif-tabs button:focus-visible { outline: 2px solid var(--hd-primary); outline-offset: 2px; border-radius: 4px; }
.hd-notif-list { flex: 1; overflow-y: auto; padding: 4px 6px 6px; overscroll-behavior: contain; }
.hd-notif-loading { padding: 14px 10px; }
.hd-notif-group h4 {
  margin: 0; padding: 12px 10px 4px; font-size: 11px; font-weight: 600;
  text-transform: uppercase; letter-spacing: 0.05em; color: var(--hd-muted);
}
.hd-notif-empty { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 40px 24px; text-align: center; }
.hd-notif-empty span {
  display: grid; place-items: center; width: 48px; height: 48px; margin-bottom: 8px; border-radius: 50%;
  background: var(--hd-bg-2); color: var(--hd-muted); font-size: 22px;
}
.hd-notif-empty p { margin: 0; font-size: 14px; font-weight: 600; color: var(--hd-text); }
.hd-notif-empty small { font-size: 12.5px; color: var(--hd-muted); max-width: 260px; }
.hd-notif-foot {
  display: flex; align-items: center; justify-content: center; gap: 6px; padding: 12px;
  border-top: 1px solid var(--hd-border); color: var(--fs-primary-text, #1c45bd);
  font-size: 13px; font-weight: 500; text-decoration: none;
}
.hd-notif-foot:hover { background: var(--hd-bg-2); }
.hd-notif-foot:focus-visible { outline: 2px solid var(--hd-primary); outline-offset: -2px; }

/* Cloche : légère oscillation à l'arrivée d'une notification */
.hd-notif-btn.is-ringing :deep(.bi-bell) { animation: hd-ring 0.9s ease; transform-origin: 50% 10%; }
@keyframes hd-ring { 0%, 100% { transform: rotate(0); } 20% { transform: rotate(14deg); } 40% { transform: rotate(-12deg); } 60% { transform: rotate(8deg); } 80% { transform: rotate(-4deg); } }
@media (prefers-reduced-motion: reduce) { .hd-notif-btn.is-ringing :deep(.bi-bell) { animation: none; } }

.hd-profile-card { display: flex; align-items: center; gap: 12px; padding: 10px 10px 14px; border-bottom: 1px solid var(--hd-border); margin-bottom: 6px; }
.hd-profile-card-name { margin: 0; font-size: 14px; font-weight: 600; color: var(--hd-text); }
.hd-profile-card-mail { margin: 2px 0 0; font-size: 12.5px; color: var(--hd-muted); word-break: break-all; }
.hd-menu-item {
  text-decoration: none;
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
