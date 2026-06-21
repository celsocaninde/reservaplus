<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use CommonGLPI;
use Html;
use Session;

class Dashboard extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('Reserva Plus', 'reservaplus');
    }

    public static function getMenuName(): string
    {
        return self::getTypeName(1);
    }

    public static function getIcon(): string
    {
        return 'ti ti-calendar-plus';
    }

    public static function canView(): bool
    {
        return Profile::hasAnyRight();
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        $menu = [
            'title' => self::getMenuName(),
            'page'  => self::getUrl('dashboard.php'),
            'icon'  => self::getIcon(),
            'links' => [
                'search' => self::getUrl('reservation.php'),
            ],
            'options' => [],
        ];

        if (ReservationRequest::canView()) {
            $menu['options']['reservaplus_dashboard'] = [
                'title' => __('Painel', 'reservaplus'),
                'page'  => self::getUrl('dashboard.php'),
                'icon'  => 'ti ti-layout-dashboard',
            ];
            $menu['options']['reservaplus_reservations'] = [
                'title' => __('Reservas', 'reservaplus'),
                'page'  => self::getUrl('reservation.php'),
                'icon'  => 'ti ti-list-details',
                'links' => [
                    'add'    => self::getUrl('reservation.form.php'),
                    'search' => self::getUrl('reservation.php'),
                ],
            ];
            $menu['options']['reservaplus_calendar'] = [
                'title' => __('Calendário', 'reservaplus'),
                'page'  => self::getUrl('calendar.php'),
                'icon'  => 'ti ti-calendar',
            ];
        }

        if (Block::canView()) {
            $menu['options']['reservaplus_blocks'] = [
                'title' => __('Bloqueios de horário', 'reservaplus'),
                'page'  => self::getUrl('block.php'),
                'icon'  => 'ti ti-calendar-x',
                'links' => [
                    'add'    => self::getUrl('block.form.php'),
                    'search' => self::getUrl('block.php'),
                ],
            ];
        }

        if (Report::canView()) {
            $menu['options']['reservaplus_reports'] = [
                'title' => __('Relatórios', 'reservaplus'),
                'page'  => self::getUrl('report.php'),
                'icon'  => 'ti ti-chart-bar',
            ];
        }

        if (ReservationRequest::isGlpiAdmin() || ReservationRequest::canManageAllRequests()) {
            $menu['options']['reservaplus_itemgroups'] = [
                'title' => __('Grupos por sala', 'reservaplus'),
                'page'  => self::getUrl('itemgroup.php'),
                'icon'  => 'ti ti-users-group',
            ];
        }

        if (Config::canView()) {
            $menu['options']['reservaplus_config'] = [
                'title' => __('Configuração', 'reservaplus'),
                'page'  => self::getUrl('config.form.php'),
                'icon'  => 'ti ti-settings',
            ];
        }

        return $menu;
    }

    public static function getUrl(string $frontFile): string
    {
        return '/plugins/reservaplus/front/' . ltrim($frontFile, '/');
    }

    /**
     * Indica se o usuário atual está na interface simplificada (self-service).
     */
    public static function isHelpdeskInterface(): bool
    {
        return Session::getCurrentInterface() === 'helpdesk';
    }

    /**
     * Cabeçalho ciente da interface: usa helpHeader na interface simplificada
     * (self-service) e o header central nos demais perfis. Sem isto, abrir uma
     * página do plugin como self-service renderiza o layout central e quebra a
     * navegação simplificada.
     */
    public static function renderHeader(string $title): void
    {
        if (self::isHelpdeskInterface()) {
            // 'reservaplus' é o item de topo injetado via redefine_menus.
            Html::helpHeader($title, 'reservaplus');
            return;
        }

        Html::header($title, $_SERVER['PHP_SELF'] ?? '', 'tools', self::class);
    }

    public static function renderFooter(): void
    {
        if (self::isHelpdeskInterface()) {
            Html::helpFooter();
            return;
        }

        Html::footer();
    }

    /**
     * Versão do asset para cache-busting. Usa o filemtime do arquivo para que
     * qualquer alteração em CSS/JS recarregue automaticamente, sem precisar
     * subir a versão do plugin (que o coloca em estado "precisa atualizar").
     */
    private static function assetVersion(string $relativePath): string
    {
        $fullPath = dirname(__DIR__) . '/public/' . ltrim($relativePath, '/');
        $mtime = is_file($fullPath) ? (int) filemtime($fullPath) : 0;

        return $mtime > 0 ? (string) $mtime : PLUGIN_RESERVAPLUS_VERSION;
    }

    public static function includeAssets(bool $includeScript = true): void
    {
        static $cssIncluded = false;
        static $scriptIncluded = false;

        if (!$cssIncluded) {
            echo Html::css('/plugins/reservaplus/css/reservaplus.css', ['version' => self::assetVersion('css/reservaplus.css')], false);
            $cssIncluded = true;
        }

        if ($includeScript && !$scriptIncluded) {
            echo Html::script('/plugins/reservaplus/js/reservaplus.js', ['version' => self::assetVersion('js/reservaplus.js')], false);
            $scriptIncluded = true;
        }
    }

    public static function showDashboard(): void
    {
        $stats = self::getStats();

        // Quem não administra reservas só enxerga as próprias na lista de
        // "recentes" (que exibe nomes de usuários). Evita vazar reservas de
        // terceiros para perfis self-service que cheguem a esta página.
        $recentWhere = [];
        if (!ReservationRequest::isGlpiAdmin() && !ReservationRequest::canManageAllRequests()) {
            $recentWhere[ReservationRequest::getTable() . '.users_id_requester'] = (int) Session::getLoginUserID();
        }

        $recentRequests    = ReservationRequest::getFiltered($recentWhere, 6);
        $todayReservations = ReservationRequest::getTodayNativeReservations(6);

        echo "<div class='reservaplus-shell'>";
        self::showHero();
        self::showStats($stats);
        self::showAvailableNow();

        echo "<div class='reservaplus-grid reservaplus-grid-main'>";
        echo "<section class='reservaplus-panel'>";
        echo "<div class='reservaplus-panel-header'>";
        echo '<div>';
        echo '<h2>' . __('Reservas de hoje', 'reservaplus') . '</h2>';
        echo '<p>' . __('Reservas nativas do GLPI ativas no dia atual.', 'reservaplus') . '</p>';
        echo '</div>';
        echo "<a class='btn btn-outline-primary' href='" . self::getUrl('calendar.php') . "'><i class='ti ti-calendar'></i> " . __('Calendário', 'reservaplus') . '</a>';
        echo '</div>';
        self::showNativeReservations($todayReservations);
        echo '</section>';

        echo "<aside class='reservaplus-panel'>";
        echo "<div class='reservaplus-panel-header'>";
        echo '<div>';
        echo '<h2>' . __('Reservas recentes', 'reservaplus') . '</h2>';
        echo '<p>' . __('Últimas reservas registradas no sistema.', 'reservaplus') . '</p>';
        echo '</div>';
        echo "<a class='btn btn-outline-secondary' href='" . self::getUrl('reservation.php') . "'><i class='ti ti-list-details'></i> " . __('Ver todas', 'reservaplus') . '</a>';
        echo '</div>';
        self::showRecentRequests($recentRequests);
        echo '</aside>';
        echo '</div>';
        echo '</div>';
    }

    private static function showHero(): void
    {
        echo "<div class='reservaplus-hero'>";
        echo "<div class='reservaplus-hero-content'>";
        echo "<span class='reservaplus-brand-mark'><i class='ti ti-calendar-bolt'></i></span>";
        echo "<div class='reservaplus-brand-text'>";
        echo '<span class="reservaplus-kicker">' . __('Reserva Plus', 'reservaplus') . '</span>';
        echo '<h1>' . __('Central de reservas', 'reservaplus') . '</h1>';
        echo '<p>' . __('Reserve itens disponíveis, visualize no calendário e acompanhe suas reservas.', 'reservaplus') . '</p>';
        echo '</div>';
        echo '</div>';
        echo "<div class='reservaplus-actions'>";
        if (ReservationRequest::canCreate()) {
            echo "<a class='btn btn-primary' href='" . self::getUrl('reservation.form.php') . "'><i class='ti ti-plus'></i> " . __('Reservar', 'reservaplus') . '</a>';
        }
        echo "<a class='btn btn-outline-primary' href='" . self::getUrl('calendar.php') . "'><i class='ti ti-calendar-event'></i> " . __('Ver calendário', 'reservaplus') . '</a>';
        echo '</div>';
        echo '</div>';
    }

    private static function showStats(array $stats): void
    {
        echo "<div class='reservaplus-grid reservaplus-stats'>";
        self::showStatCard(__('Hoje', 'reservaplus'), (string) $stats['today'], __('Reservas ativas agora', 'reservaplus'), 'ti ti-calendar-check', 'primary');
        self::showStatCard(__('Minhas', 'reservaplus'), (string) $stats['mine'], __('Minhas reservas ativas', 'reservaplus'), 'ti ti-user-check', 'accent');
        self::showStatCard(__('Bloqueios', 'reservaplus'), (string) $stats['blocks'], __('Bloqueios de horário ativos', 'reservaplus'), 'ti ti-calendar-x', 'rose');
        self::showStatCard(__('Este mês', 'reservaplus'), (string) $stats['month'], __('Reservas registradas no mês', 'reservaplus'), 'ti ti-chart-bar', 'violet');
        echo '</div>';
    }

    private static function showAvailableNow(): void
    {
        $items = (new AvailabilityService())->availableNow(60);

        echo "<section class='reservaplus-panel reservaplus-available-now'>";
        echo "<div class='reservaplus-panel-header'>";
        echo '<div>';
        echo '<h2><i class="ti ti-bolt"></i> ' . __('Disponível agora', 'reservaplus') . '</h2>';
        echo '<p>' . __('Itens livres para reservar na próxima hora.', 'reservaplus') . '</p>';
        echo '</div>';
        echo "<a class='btn btn-outline-primary' href='" . self::getUrl('reservation.form.php') . "'><i class='ti ti-plus'></i> " . __('Nova reserva', 'reservaplus') . '</a>';
        echo '</div>';

        if ($items === []) {
            self::showEmptyState(__('Nada livre agora.', 'reservaplus'), __('Todos os itens estão reservados ou bloqueados na próxima hora.', 'reservaplus'));
            echo '</section>';
            return;
        }

        $canCreate = ReservationRequest::canCreate();
        echo "<div class='reservaplus-available-grid'>";
        foreach ($items as $it) {
            echo "<div class='reservaplus-available-card'>";
            echo "<span class='reservaplus-available-badge'><i class='ti ti-circle-check'></i> " . __('Livre', 'reservaplus') . '</span>';
            echo '<strong>' . Html::cleanInputText((string) $it['label']) . '</strong>';
            echo '<small>' . Html::cleanInputText((string) $it['typelabel']) . '</small>';
            if ($canCreate) {
                $url = self::getUrl('reservation.form.php') . '?item=' . (int) $it['reservationitems_id'];
                echo "<a class='btn btn-sm btn-primary' href='" . $url . "'><i class='ti ti-plus'></i> " . __('Reservar', 'reservaplus') . '</a>';
            }
            echo '</div>';
        }
        echo '</div>';
        echo '</section>';
    }

    private static function showStatCard(string $title, string $value, string $subtitle, string $icon, string $variant = 'primary'): void
    {
        $variant = preg_replace('/[^a-z]/', '', $variant) ?: 'primary';
        echo "<div class='reservaplus-stat reservaplus-stat--" . $variant . "'>";
        echo "<span class='reservaplus-stat-icon'><i class='" . Html::cleanInputText($icon) . "'></i></span>";
        echo '<strong>' . Html::cleanInputText($value) . '</strong>';
        echo '<span>' . Html::cleanInputText($title) . '</span>';
        echo '<small>' . Html::cleanInputText($subtitle) . '</small>';
        echo '</div>';
    }

    private static function showNativeReservations(array $rows): void
    {
        if ($rows === []) {
            self::showEmptyState(__('Nenhuma reserva para hoje.', 'reservaplus'), __('O dia está livre por enquanto.', 'reservaplus'));
            return;
        }

        echo "<div class='reservaplus-list'>";
        foreach ($rows as $row) {
            $itemId = (int) ($row['reservationitems_id'] ?? 0);
            $itemName = __('Item reservável', 'reservaplus') . ' #' . $itemId;
            if ($itemId > 0) {
                $resItem = new \ReservationItem();
                if ($resItem->getFromDB($itemId)) {
                    $itype = (string) ($resItem->fields['itemtype'] ?? '');
                    $iid   = (int) ($resItem->fields['items_id'] ?? 0);
                    if ($itype !== '' && class_exists($itype) && $iid > 0) {
                        $obj = new $itype();
                        if ($obj->getFromDB($iid)) {
                            $n = method_exists($obj, 'getName') ? $obj->getName() : '';
                            if ($n !== '') {
                                $itemName = $n;
                            }
                        }
                    }
                }
            }
            $begin = (string) ($row['begin'] ?? '');
            $end   = (string) ($row['end'] ?? '');
            $beginFmt = $begin !== '' ? date('H:i', strtotime($begin)) : '';
            $endFmt   = $end   !== '' ? date('H:i', strtotime($end))   : '';
            echo "<div class='reservaplus-list-row'>";
            echo '<div>';
            echo '<strong>' . Html::cleanInputText($itemName) . '</strong>';
            echo '<span>' . Html::cleanInputText($beginFmt . ($endFmt !== '' ? ' – ' . $endFmt : '')) . '</span>';
            echo '</div>';
            echo "<span class='reservaplus-badge reservaplus-badge-approved'>" . __('Confirmada', 'reservaplus') . '</span>';
            echo '</div>';
        }
        echo '</div>';
    }

    private static function showRecentRequests(array $rows): void
    {
        if ($rows === []) {
            self::showEmptyState(__('Nenhuma reserva registrada.', 'reservaplus'), __('As reservas criadas aparecerão aqui.', 'reservaplus'));
            return;
        }

        echo "<div class='reservaplus-list'>";
        foreach ($rows as $row) {
            $itemName = ReservationRequest::getItemDisplayName($row);
            $begin    = (string) ($row['begin'] ?? '');
            $end      = (string) ($row['end'] ?? '');
            $beginFmt = $begin !== '' ? date('d/m H:i', strtotime($begin)) : '';
            $endFmt   = $end   !== '' ? date('H:i', strtotime($end)) : '';
            echo "<div class='reservaplus-list-row'>";
            echo '<div>';
            echo '<strong>' . Html::cleanInputText($itemName) . '</strong>';
            echo '<span>' . Html::cleanInputText($beginFmt . ($endFmt !== '' ? ' – ' . $endFmt : '')) . '</span>';
            echo '<small>' . Html::cleanInputText(ReservationRequest::getUserDisplayName($row)) . '</small>';
            echo '</div>';
            echo "<span class='reservaplus-badge reservaplus-badge-approved'>" . __('Ativa', 'reservaplus') . '</span>';
            echo '</div>';
        }
        echo '</div>';
    }

    private static function showEmptyState(string $title, string $subtitle): void
    {
        echo "<div class='reservaplus-empty'>";
        echo "<i class='ti ti-calendar-smile'></i>";
        echo '<strong>' . Html::cleanInputText($title) . '</strong>';
        echo '<span>' . Html::cleanInputText($subtitle) . '</span>';
        echo '</div>';
    }

    private static function getStats(): array
    {
        global $DB;

        $today    = date('Y-m-d 00:00:00');
        $tomorrow = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $monthStart = date('Y-m-01 00:00:00');
        $monthEnd   = date('Y-m-t 23:59:59');
        $userId     = (int) Session::getLoginUserID();

        $stats = [
            'today'  => 0,
            'mine'   => 0,
            'blocks' => 0,
            'month'  => 0,
        ];

        if ($DB->tableExists('glpi_reservations')) {
            $row = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => 'glpi_reservations',
                'WHERE' => [
                    'begin' => ['<', $tomorrow],
                    'end'   => ['>', $today],
                ],
            ])->current();
            $stats['today'] = (int) ($row['cpt'] ?? 0);
        }

        if ($DB->tableExists(ReservationRequest::getTable())) {
            $row = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => ReservationRequest::getTable(),
                'WHERE' => [
                    'users_id_requester' => $userId,
                    'status'             => ReservationRequest::STATUS_CREATED,
                    'end'                => ['>', $today],
                ],
            ])->current();
            $stats['mine'] = (int) ($row['cpt'] ?? 0);

            $row = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => ReservationRequest::getTable(),
                'WHERE' => [
                    'date_creation' => ['>=', $monthStart],
                    ['date_creation' => ['<=', $monthEnd]],
                ],
            ])->current();
            $stats['month'] = (int) ($row['cpt'] ?? 0);
        }

        if ($DB->tableExists(Block::getTable())) {
            $row = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => Block::getTable(),
                'WHERE' => [
                    'is_active' => 1,
                    'begin'     => ['<', $tomorrow],
                    'end'       => ['>', $today],
                ],
            ])->current();
            $stats['blocks'] = (int) ($row['cpt'] ?? 0);
        }

        return $stats;
    }
}
