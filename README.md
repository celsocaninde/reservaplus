<div align="center">

# 📅 Reserva Plus

**Um fluxo moderno de reservas para o GLPI — sem tocar no core.**

Painel executivo, reserva em massa, recorrência, calendário visual, regras, bloqueios, aprovações e webhooks, reaproveitando as reservas nativas do GLPI.

![Versão](https://img.shields.io/badge/versão-0.2.2-2563eb)
![GLPI](https://img.shields.io/badge/GLPI-11.0.x-1f0d50)
![PHP](https://img.shields.io/badge/PHP-8.2%2B%20(pronto%20p%2F%208.5)-777bb4)
![Licença](https://img.shields.io/badge/licença-GPLv3%2B-2b7a0b)
![Status](https://img.shields.io/badge/status-alpha-e8590c)

</div>

---

## ✨ O que é

O **Reserva Plus** entrega uma experiência completa de reservas dentro do GLPI: uma interface própria, rápida e responsiva, mantendo as reservas confirmadas em `glpi_reservations` (nativo) e guardando metadados, recorrência, regras, bloqueios, aprovações e notificações em tabelas próprias do plugin.

> **Princípio central:** o core do GLPI permanece **intocado**. Nada de substituir arquivos nem editar tabelas nativas fora das APIs/classes do GLPI.

---

## 🚀 Destaques

| Recurso | Descrição |
| --- | --- |
| 🖥️ **Dashboard moderno** | Toolbar superior, cards de status, painel de reservas do dia e bloco **"Disponível agora"** (itens livres na próxima hora + reservar rápido). |
| ⚡ **Reserva em massa** | Vários itens no mesmo período em uma só ação, com **sucesso parcial** e resumo do que foi/não foi reservado. |
| 🔁 **Recorrência** | Diária, semanal (dias da semana) e mensal, com **grupo de recorrência** para cancelar uma ocorrência ou a série inteira. |
| 🗓️ **Calendário visual** | Visão mensal mostrando **quem vai usar** cada horário; clique em horário livre abre nova reserva; bloqueios aparecem no calendário. |
| 🧰 **Atalhos inteligentes** | Durações rápidas (1h/2h/4h, manhã, tarde, dia todo), seleção de itens por categoria e **busca de horários livres** (interseção para múltiplos itens). |
| 🙋 **Self-service seguro** | O usuário vê tudo em leitura, mas só cancela/exclui **as próprias reservas**. Reservas de terceiros são imutáveis. |
| 📐 **Regras** | Cadastro, listagem e ativação/desativação de regras de reserva por perfil/entidade/tipo de item. |
| 🚫 **Bloqueios** | Bloqueio global ou por item, com motivo e período de validade. |
| ✅ **Aprovações** | Fluxo de aprovação para reservas que exigem validação. |
| 🔔 **Webhook assinado** | POST JSON com **HMAC-SHA256** em criação/cancelamento de reserva, configurável na tela de configuração. |

---

## 🧩 Como funciona

```
Usuário → Reserva Plus → calcula disponibilidade (reservas nativas + bloqueios + regras)
                       → confirma → revalida conflitos
                       → cria reserva nativa em glpi_reservations
                       → grava metadados/recorrência + dispara webhook/notificação
```

1. As **reservas confirmadas** ficam em `glpi_reservations` (nativo do GLPI).
2. **Metadados, recorrência e estado** ficam nas tabelas do plugin.
3. **Regras, bloqueios, aprovações e notificações** são isolados no plugin.
4. As telas vivem em **Ferramentas → Reserva Plus** (interface central) e como **item de topo** no menu simplificado (self-service).

---

## 📋 Requisitos

- **GLPI** `>= 11.0.0` e `< 11.1.0`
- **PHP** `8.2+` (escrito com `declare(strict_types=1)` e pronto para PHP 8.5)

---

## 📦 Instalação

```bash
# 1. Copie a pasta para o diretório de plugins do GLPI
#    (a pasta DEVE se chamar "reservaplus", sem hífen)
cp -r reservaplus /var/www/glpi/plugins/reservaplus

# 2. No GLPI: Configurar → Plugins → Reserva Plus → Instalar → Ativar
```

> ⚠️ O nome técnico é **`reservaplus`** (sem hífen). O GLPI deriva as funções do plugin do nome da pasta, e a pasta **não deve mudar** depois de instalada.

A instalação cria as tabelas do plugin, registra as permissões e concede acesso total ao perfil **Super-Admin** automaticamente.

---

## 🔐 Permissões

O plugin adiciona uma aba de permissões no **Perfil** do GLPI, cobrindo:

- **Reservas** — ver, criar, atualizar, excluir. No self-service, atualizar/excluir limitam-se às **reservas do próprio usuário**.
- **Regras** — ver, criar, atualizar, excluir.
- **Bloqueios** — ver, criar, atualizar, excluir.
- **Relatórios** — visualizar.
- **Configuração** — ver e atualizar.

---

## ⚙️ Configuração

Em **Ferramentas → Reserva Plus → Configurações** (ou pela engrenagem do plugin):

- Duração padrão da reserva.
- Horário comercial (início/fim).
- Recorrência habilitada.
- Notificações.
- **Webhook**: URL de destino e segredo HMAC-SHA256 para reservas criadas/canceladas.

---

## 🗂️ Estrutura do projeto

```text
reservaplus/
├── setup.php / hook.php / reservaplus.xml   # registro do plugin
├── src/                                     # classes (PSR-4: GlpiPlugin\Reservaplus)
│   ├── Dashboard.php  ReservationRequest.php  ReservationService.php
│   ├── AvailabilityService.php  Rule.php  Block.php  Approval.php
│   ├── ItemGroup.php  Config.php  Profile.php  NotificationService.php  Report.php
├── front/                                   # telas (dashboard, reservation, calendar,
│                                            #   rule, block, approval, report, config…)
├── ajax/                                    # events.php, availability.php, freeslots.php
├── public/css|js/                           # assets servidos pela web (padrão GLPI 11)
├── sql/                                      # install.php / uninstall.php
└── locales/                                 # traduções (pt_BR, en_GB)
```

> No GLPI 11 os assets web ficam em `public/`. Ex.: `public/css/reservaplus.css` é servido em `/plugins/reservaplus/css/reservaplus.css`.

---

## 🗃️ Modelo de dados

| Tabela | Conteúdo |
| --- | --- |
| `glpi_plugin_reservaplus_configs` | Configurações gerais do plugin. |
| `glpi_plugin_reservaplus_requests` | Metadados e recorrência das reservas. |
| `glpi_plugin_reservaplus_rules` | Regras por perfil, entidade e tipo de item. |
| `glpi_plugin_reservaplus_blocks` | Bloqueios de disponibilidade. |
| `glpi_plugin_reservaplus_approvals` | Fluxo de aprovação de reservas. |
| `glpi_plugin_reservaplus_item` | Grupos/relacionamento de itens reserváveis. |
| `glpi_plugin_reservaplus_notification` | Auditoria de notificações. |

---

## 🛣️ Roadmap

> O plugin está em **alpha**. O núcleo (criar/recorrer/calendário/bloqueios/regras) já funciona; o que segue está em andamento:

- [ ] **Regras ativas** — aplicar limites de duração máxima e antecedência mínima/máxima na validação.
- [ ] **Filtros da lista** — categoria, local, responsável, status, disponibilidade e entidade.
- [ ] **Calendário** — visões de semana e dia; clique no evento abre detalhes.
- [ ] **Notificações** — e-mail em reserva criada/cancelada, lembretes e log.
- [ ] **Relatórios** — uso por item/categoria/entidade/solicitante e exportação CSV.
- [ ] **Reservas** — cancelamento individual base e duplicação por pré-preenchimento.
- [ ] **Polimento** — i18n completa (pt_BR/en_GB), acessibilidade e estados vazios.

---

## 🧪 Desenvolvimento

- Código com `declare(strict_types=1)`, tipagem de argumentos/retornos e sem propriedades dinâmicas (compatível com PHP 8.5).
- MVP usa `front/*.php` e `ajax/*.php` (sem controllers Symfony) por compatibilidade com GLPI 11.0.5.
- Validação rápida de sintaxe dentro do container:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

---

## 📄 Licença e autores

Distribuído sob **GPL-3.0-or-later**.

Desenvolvido por **Celso** e **Codex**.
</content>
</invoke>
