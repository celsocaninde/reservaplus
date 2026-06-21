(function () {
  // --- Utilities ---

  function toDateKey(date) {
    return [
      date.getFullYear(),
      String(date.getMonth() + 1).padStart(2, "0"),
      String(date.getDate()).padStart(2, "0"),
    ].join("-");
  }

  function monthLabel(date) {
    return date.toLocaleDateString("pt-BR", {
      month: "long",
      year: "numeric",
    });
  }

  function eventDateKey(value) {
    return value ? String(value).slice(0, 10) : "";
  }

  function eventCssClass(event) {
    if (event.type === "request" && event.status) {
      return "request " + event.status;
    }
    return event.type || "request";
  }

  function formatTime(datetime) {
    if (!datetime) return "";
    var m = String(datetime).match(/(\d{2}):(\d{2})/);
    return m ? m[1] + ":" + m[2] : "";
  }

  function formatDateTimeLocal(date) {
    return (
      [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, "0"),
        String(date.getDate()).padStart(2, "0"),
      ].join("-") +
      "T" +
      [
        String(date.getHours()).padStart(2, "0"),
        String(date.getMinutes()).padStart(2, "0"),
      ].join(":")
    );
  }

  function defaultReservationHours(dateKey) {
    var selected = new Date(dateKey + "T09:00:00");
    var now = new Date();
    if (dateKey === toDateKey(now) && now.getHours() >= 9) {
      selected = new Date(now);
      selected.setMinutes(0, 0, 0);
      selected.setHours(selected.getHours() + 1);
    }
    var end = new Date(selected);
    end.setHours(selected.getHours() + 1);
    return { begin: selected, end: end };
  }

  function statusLabel(status) {
    var labels = {
      created: "Ativa",
      pending: "Pendente",
      approved: "Aprovada",
      refused: "Recusada",
      cancelled: "Cancelada",
    };
    return labels[status] || status;
  }

  function statusBadgeClass(status) {
    var map = {
      created: "reservaplus-badge-approved",
      approved: "reservaplus-badge-approved",
      pending: "reservaplus-badge-pending",
      refused: "reservaplus-badge-danger",
      cancelled: "",
    };
    return map[status] || "";
  }

  // --- Month view rendering ---

  function renderCalendar(calendar, currentDate, events) {
    var title = calendar.querySelector(".reservaplus-calendar-title");
    var grid = calendar.querySelector(".reservaplus-calendar-grid, .reservaplus-week-grid, .reservaplus-day-view");
    var weekdays = ["Dom", "Seg", "Ter", "Qua", "Qui", "Sex", "Sáb"];
    var first = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1);
    var start = new Date(first);
    start.setDate(first.getDate() - first.getDay());

    title.textContent = monthLabel(currentDate);
    calendar.classList.remove("is-loading");
    grid.className = "reservaplus-calendar-grid";
    grid.innerHTML = "";

    weekdays.forEach(function (day) {
      var cell = document.createElement("div");
      cell.className = "reservaplus-calendar-weekday";
      cell.textContent = day;
      grid.appendChild(cell);
    });

    for (var index = 0; index < 42; index += 1) {
      var date = new Date(start);
      date.setDate(start.getDate() + index);

      var key = toDateKey(date);
      var isMuted = date.getMonth() !== currentDate.getMonth();
      var todayKey = toDateKey(new Date());

      var day = document.createElement("div");
      day.className =
        "reservaplus-calendar-day" +
        (isMuted ? " is-muted" : "") +
        (key === todayKey ? " is-today" : "");
      day.setAttribute("data-reservaplus-day", key);
      day.setAttribute("role", "button");
      day.setAttribute("tabindex", "0");
      day.setAttribute(
        "aria-label",
        "Ver reservas de " + date.toLocaleDateString("pt-BR")
      );

      var number = document.createElement("div");
      number.className = "reservaplus-calendar-day-number";
      number.textContent = String(date.getDate());
      day.appendChild(number);

      var dayEvents = events.filter(function (event) {
        return eventDateKey(event.start) === key;
      });

      dayEvents.slice(0, 3).forEach(function (event) {
        var item = document.createElement("div");
        item.className = "reservaplus-calendar-event " + eventCssClass(event);
        item.title = event.title || "";
        item.textContent = event.title || "";
        day.appendChild(item);
      });

      if (dayEvents.length > 3) {
        var more = document.createElement("div");
        more.className = "reservaplus-calendar-more";
        more.textContent = "+" + (dayEvents.length - 3) + " mais";
        day.appendChild(more);
      }

      grid.appendChild(day);
    }
  }

  // --- Week view rendering ---

  function renderWeekView(calendar, currentDate, events) {
    var title = calendar.querySelector(".reservaplus-calendar-title");
    var grid = calendar.querySelector(".reservaplus-calendar-grid, .reservaplus-week-grid, .reservaplus-day-view");
    var weekdays = ["Dom", "Seg", "Ter", "Qua", "Qui", "Sex", "Sáb"];

    var weekStart = new Date(currentDate);
    weekStart.setDate(weekStart.getDate() - weekStart.getDay());
    var weekEnd = new Date(weekStart);
    weekEnd.setDate(weekStart.getDate() + 6);

    var opts = { day: "2-digit", month: "long", year: "numeric" };
    title.textContent =
      weekStart.toLocaleDateString("pt-BR", opts) +
      " – " +
      weekEnd.toLocaleDateString("pt-BR", opts);

    calendar.classList.remove("is-loading");
    grid.className = "reservaplus-week-grid";
    grid.innerHTML = "";

    var todayKey = toDateKey(new Date());

    for (var i = 0; i < 7; i++) {
      var date = new Date(weekStart);
      date.setDate(weekStart.getDate() + i);
      var key = toDateKey(date);

      var col = document.createElement("div");
      col.className =
        "reservaplus-week-col" + (key === todayKey ? " is-today" : "");
      col.setAttribute("data-reservaplus-day", key);
      col.setAttribute("role", "button");
      col.setAttribute("tabindex", "0");
      col.setAttribute("aria-label", "Ver reservas de " + date.toLocaleDateString("pt-BR"));

      var header = document.createElement("div");
      header.className = "reservaplus-week-col-header";

      var dayname = document.createElement("span");
      dayname.className = "reservaplus-week-dayname";
      dayname.textContent = weekdays[i];

      var daynum = document.createElement("span");
      daynum.className = "reservaplus-week-daynum";
      daynum.textContent = String(date.getDate());

      header.appendChild(dayname);
      header.appendChild(daynum);
      col.appendChild(header);

      var dayEvents = events.filter(function (e) {
        return eventDateKey(e.start) === key;
      });
      dayEvents.sort(function (a, b) {
        return String(a.start || "").localeCompare(String(b.start || ""));
      });

      var eventsDiv = document.createElement("div");
      eventsDiv.className = "reservaplus-week-events";

      if (dayEvents.length === 0) {
        var empty = document.createElement("div");
        empty.className = "reservaplus-week-empty";
        var emptyIcon = document.createElement("i");
        emptyIcon.className = "ti ti-calendar-plus";
        var emptyText = document.createElement("span");
        emptyText.textContent = "Livre";
        empty.appendChild(emptyIcon);
        empty.appendChild(emptyText);
        eventsDiv.appendChild(empty);
      } else {
        dayEvents.forEach(function (event) {
          var item = document.createElement("div");
          item.className = "reservaplus-calendar-event " + eventCssClass(event);
          var time = formatTime(event.start);
          item.title = (time ? time + " " : "") + (event.title || "");
          if (time) {
            var timeSpan = document.createElement("span");
            timeSpan.className = "reservaplus-week-event-time";
            timeSpan.textContent = time + " ";
            item.appendChild(timeSpan);
          }
          var titleSpan = document.createElement("span");
          titleSpan.textContent = event.title || "";
          item.appendChild(titleSpan);
          eventsDiv.appendChild(item);
        });
      }

      col.appendChild(eventsDiv);
      grid.appendChild(col);
    }
  }

  // --- Day view rendering ---

  function renderDayView(calendar, currentDate, events) {
    var title = calendar.querySelector(".reservaplus-calendar-title");
    var grid = calendar.querySelector(".reservaplus-calendar-grid, .reservaplus-week-grid, .reservaplus-day-view");

    var key = toDateKey(currentDate);
    title.textContent = currentDate.toLocaleDateString("pt-BR", {
      weekday: "long",
      day: "2-digit",
      month: "long",
      year: "numeric",
    });

    calendar.classList.remove("is-loading");
    grid.className = "reservaplus-day-view";
    grid.innerHTML = "";

    var dayEvents = events.filter(function (e) {
      return eventDateKey(e.start) === key;
    });
    dayEvents.sort(function (a, b) {
      return String(a.start || "").localeCompare(String(b.start || ""));
    });

    if (dayEvents.length === 0) {
      var empty = document.createElement("div");
      empty.className = "reservaplus-dayview-empty";
      var icon = document.createElement("i");
      icon.className = "ti ti-calendar-off";
      var span = document.createElement("span");
      span.textContent = "Nenhuma reserva neste dia.";
      empty.appendChild(icon);
      empty.appendChild(span);
      grid.appendChild(empty);
    } else {
      dayEvents.forEach(function (event) {
        var row = document.createElement("div");
        row.className = "reservaplus-dayview-event " + eventCssClass(event);
        row.setAttribute("data-reservaplus-day", key);
        row.setAttribute("role", "button");
        row.setAttribute("tabindex", "0");

        var timeStart = formatTime(event.start);
        var timeEnd = event.end ? formatTime(event.end) : "";
        var timeText = timeStart + (timeEnd ? " – " + timeEnd : "");

        var timeDiv = document.createElement("div");
        timeDiv.className = "reservaplus-dayview-time";
        timeDiv.textContent = timeText;

        var infoDiv = document.createElement("div");
        infoDiv.className = "reservaplus-dayview-info";
        var strong = document.createElement("strong");
        strong.textContent = event.title || "";
        infoDiv.appendChild(strong);

        row.appendChild(timeDiv);
        row.appendChild(infoDiv);
        grid.appendChild(row);
      });
    }
  }

  // --- Loading state ---

  function setLoadingState(calendar, currentDate) {
    var title = calendar.querySelector(".reservaplus-calendar-title");
    var grid = calendar.querySelector(".reservaplus-calendar-grid, .reservaplus-week-grid, .reservaplus-day-view");

    calendar.classList.add("is-loading");
    if (title) {
      title.textContent = monthLabel(currentDate);
    }
    if (grid) {
      grid.className = "reservaplus-calendar-grid";
      grid.innerHTML = "";
      for (var i = 0; i < 42; i++) {
        var skeleton = document.createElement("div");
        skeleton.className =
          "reservaplus-calendar-day reservaplus-calendar-skeleton";
        grid.appendChild(skeleton);
      }
    }
  }

  // --- Quick reservation modal ---

  function openQuickReservation(dateKey) {
    var modal = document.querySelector("[data-reservaplus-modal]");
    if (!modal || !dateKey) return;

    var dates = defaultReservationHours(dateKey);
    var beginInput = modal.querySelector("[data-reservaplus-begin]");
    var endInput = modal.querySelector("[data-reservaplus-end]");
    var label = modal.querySelector("[data-reservaplus-modal-date]");
    var firstField = modal.querySelector("select, input, textarea");
    var readableDate = new Date(dateKey + "T12:00:00").toLocaleDateString(
      "pt-BR",
      { weekday: "long", day: "2-digit", month: "long", year: "numeric" }
    );

    if (beginInput) beginInput.value = formatDateTimeLocal(dates.begin);
    if (endInput) endInput.value = formatDateTimeLocal(dates.end);
    if (label) label.textContent = readableDate;

    // Recalcula a disponibilidade ao (re)abrir o modal (mudar .value não dispara eventos)
    if (beginInput) beginInput.dispatchEvent(new Event("change"));

    modal.hidden = false;
    document.body.classList.add("reservaplus-modal-open");
    window.setTimeout(function () {
      if (firstField) firstField.focus();
    }, 50);
  }

  function closeQuickReservation() {
    var modal = document.querySelector("[data-reservaplus-modal]");
    if (!modal) return;
    modal.hidden = true;
    document.body.classList.remove("reservaplus-modal-open");
  }

  // --- Day detail modal ---

  function renderDayDetailEvent(event) {
    var config = window.reservaplusConfig || {};

    var div = document.createElement("div");
    div.className = "reservaplus-daydetail-event " + eventCssClass(event);

    var info = document.createElement("div");
    info.className = "reservaplus-daydetail-event-info";

    var titleEl = document.createElement("strong");
    titleEl.textContent = event.title || "";
    info.appendChild(titleEl);

    if (event.start && event.end) {
      var timeEl = document.createElement("span");
      timeEl.className = "reservaplus-daydetail-event-time";
      timeEl.textContent = formatTime(event.start) + " – " + formatTime(event.end);
      info.appendChild(timeEl);
    }

    if (event.type === "request" && event.status) {
      var badge = document.createElement("span");
      badge.className = "reservaplus-badge " + statusBadgeClass(event.status);
      badge.textContent = statusLabel(event.status);
      info.appendChild(badge);
    }

    div.appendChild(info);

    var canDelete = false;
    if (event.type === "request" && event.requestId) {
      if (config.isAdmin) {
        canDelete = true;
      } else {
        var uid = Number(config.currentUserId);
        if (
          uid > 0 &&
          (event.users_id_requester === uid || event.users_id_for === uid)
        ) {
          canDelete = true;
        }
      }
    }

    if (canDelete) {
      var form = document.createElement("form");
      form.method = "post";
      form.action = config.deleteActionUrl || "";
      form.className = "reservaplus-inline-form";

      function mkHidden(name, value) {
        var input = document.createElement("input");
        input.type = "hidden";
        input.name = name;
        input.value = String(value);
        return input;
      }

      form.appendChild(mkHidden("_glpi_csrf_token", config.csrfToken || ""));
      form.appendChild(mkHidden("id", event.requestId));
      form.appendChild(mkHidden("redirect", "calendar.php"));

      var deleteBtn = document.createElement("button");
      deleteBtn.type = "submit";
      deleteBtn.name = "delete";
      deleteBtn.className = "btn btn-sm btn-outline-danger";
      deleteBtn.title = "Apagar reserva";
      deleteBtn.innerHTML = "<i class='ti ti-trash'></i>";
      deleteBtn.addEventListener("click", function (e) {
        if (!window.confirm("Tem certeza que deseja apagar esta reserva?")) {
          e.preventDefault();
        }
      });
      form.appendChild(deleteBtn);

      // Cancel button for non-cancelled/refused requests
      var cancelableStatuses = ["created", "pending", "approved"];
      if (event.status && cancelableStatuses.indexOf(event.status) !== -1) {
        var cancelForm = document.createElement("form");
        cancelForm.method = "post";
        cancelForm.action = config.deleteActionUrl || "";
        cancelForm.className = "reservaplus-inline-form";
        cancelForm.appendChild(mkHidden("_glpi_csrf_token", config.csrfToken || ""));
        cancelForm.appendChild(mkHidden("id", event.requestId));
        cancelForm.appendChild(mkHidden("redirect", "calendar.php"));

        var cancelBtn = document.createElement("button");
        cancelBtn.type = "submit";
        cancelBtn.name = "cancel";
        cancelBtn.value = "1";
        cancelBtn.className = "btn btn-sm btn-outline-warning";
        cancelBtn.title = "Cancelar reserva";
        cancelBtn.innerHTML = "<i class='ti ti-ban'></i>";
        cancelBtn.addEventListener("click", function (e) {
          if (!window.confirm("Cancelar esta reserva?")) {
            e.preventDefault();
          }
        });
        cancelForm.appendChild(cancelBtn);
        div.appendChild(cancelForm);
      }

      div.appendChild(form);
    }

    return div;
  }

  function openDayDetail(dateKey, events) {
    var detail = document.querySelector("[data-reservaplus-daydetail]");
    if (!detail || !dateKey) return;

    var dayEvents = events.filter(function (e) {
      return eventDateKey(e.start) === dateKey;
    });

    var dateEl = detail.querySelector("[data-reservaplus-daydetail-date]");
    if (dateEl) {
      dateEl.textContent = new Date(
        dateKey + "T12:00:00"
      ).toLocaleDateString("pt-BR", {
        weekday: "long",
        day: "2-digit",
        month: "long",
        year: "numeric",
      });
    }

    var body = detail.querySelector("[data-reservaplus-daydetail-body]");
    if (body) {
      body.innerHTML = "";
      if (dayEvents.length === 0) {
        var empty = document.createElement("div");
        empty.className = "reservaplus-daydetail-empty";
        empty.textContent = "Nenhuma reserva neste dia.";
        body.appendChild(empty);
      } else {
        dayEvents.forEach(function (event) {
          body.appendChild(renderDayDetailEvent(event));
        });
      }
    }

    detail.setAttribute("data-reservaplus-daydetail-datekey", dateKey);
    detail.hidden = false;
    document.body.classList.add("reservaplus-modal-open");
  }

  function closeDayDetail() {
    var detail = document.querySelector("[data-reservaplus-daydetail]");
    if (!detail) return;
    detail.hidden = true;
    document.body.classList.remove("reservaplus-modal-open");
  }

  // --- Calendar init ---

  function initCalendars() {
    document
      .querySelectorAll(".reservaplus-calendar")
      .forEach(function (calendar) {
        var currentDate = new Date();
        var loadedEvents = [];
        var currentView = "month";

        function renderCurrentView() {
          if (currentView === "week") {
            renderWeekView(calendar, currentDate, loadedEvents);
          } else if (currentView === "day") {
            renderDayView(calendar, currentDate, loadedEvents);
          } else {
            renderCalendar(calendar, currentDate, loadedEvents);
          }
        }

        function doLoad() {
          var url = calendar.getAttribute("data-events-url");
          var start, end;

          if (currentView === "week") {
            start = new Date(currentDate);
            start.setDate(start.getDate() - start.getDay());
            end = new Date(start);
            end.setDate(start.getDate() + 6);
          } else if (currentView === "day") {
            start = new Date(currentDate);
            end = new Date(currentDate);
          } else {
            start = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1);
            end = new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 0);
          }

          var params = new URLSearchParams({
            start: toDateKey(start),
            end: toDateKey(end),
          });

          setLoadingState(calendar, currentDate);

          fetch(url + "?" + params.toString(), { credentials: "same-origin" })
            .then(function (r) {
              return r.json();
            })
            .then(function (payload) {
              loadedEvents = payload.events || [];
              renderCurrentView();
            })
            .catch(function () {
              loadedEvents = [];
              renderCurrentView();
            });
        }

        doLoad();

        // Click on any day cell or day-view event row opens day detail
        calendar.addEventListener("click", function (event) {
          var dayEl = event.target.closest("[data-reservaplus-day]");
          if (dayEl) {
            openDayDetail(dayEl.getAttribute("data-reservaplus-day"), loadedEvents);
          }
        });

        calendar.addEventListener("keydown", function (event) {
          if (event.key === "Enter" || event.key === " ") {
            var dayEl = event.target.closest("[data-reservaplus-day]");
            if (dayEl) {
              event.preventDefault();
              openDayDetail(
                dayEl.getAttribute("data-reservaplus-day"),
                loadedEvents
              );
            }
          }
        });

        var panel =
          calendar.closest(".reservaplus-calendar-panel") || document;

        // View switcher
        panel.querySelectorAll("[data-reservaplus-view]").forEach(function (btn) {
          btn.addEventListener("click", function () {
            currentView = btn.getAttribute("data-reservaplus-view") || "month";
            panel.querySelectorAll("[data-reservaplus-view]").forEach(function (b) {
              b.classList.remove("btn-secondary");
              b.classList.add("btn-outline-secondary");
            });
            btn.classList.remove("btn-outline-secondary");
            btn.classList.add("btn-secondary");
            doLoad();
          });
        });

        var prevBtn = panel.querySelector(".reservaplus-calendar-prev");
        var nextBtn = panel.querySelector(".reservaplus-calendar-next");
        var todayBtn = panel.querySelector(".reservaplus-calendar-today");
        var openTodayBtn = panel.querySelector("[data-reservaplus-open-today]");

        if (prevBtn) {
          prevBtn.addEventListener("click", function () {
            if (currentView === "week") {
              currentDate = new Date(currentDate);
              currentDate.setDate(currentDate.getDate() - 7);
            } else if (currentView === "day") {
              currentDate = new Date(currentDate);
              currentDate.setDate(currentDate.getDate() - 1);
            } else {
              currentDate = new Date(
                currentDate.getFullYear(),
                currentDate.getMonth() - 1,
                1
              );
            }
            doLoad();
          });
        }

        if (nextBtn) {
          nextBtn.addEventListener("click", function () {
            if (currentView === "week") {
              currentDate = new Date(currentDate);
              currentDate.setDate(currentDate.getDate() + 7);
            } else if (currentView === "day") {
              currentDate = new Date(currentDate);
              currentDate.setDate(currentDate.getDate() + 1);
            } else {
              currentDate = new Date(
                currentDate.getFullYear(),
                currentDate.getMonth() + 1,
                1
              );
            }
            doLoad();
          });
        }

        if (todayBtn) {
          todayBtn.addEventListener("click", function () {
            currentDate = new Date();
            doLoad();
          });
        }

        if (openTodayBtn) {
          openTodayBtn.addEventListener("click", function () {
            openQuickReservation(toDateKey(new Date()));
          });
        }
      });
  }

  // --- Day detail modal init ---

  function initDayDetailModal() {
    var detail = document.querySelector("[data-reservaplus-daydetail]");
    if (!detail) return;

    detail.addEventListener("click", function (event) {
      if (event.target.closest("[data-reservaplus-daydetail-close]")) {
        closeDayDetail();
        return;
      }
      if (event.target === detail) {
        closeDayDetail();
      }
    });

    var reserveBtn = detail.querySelector("[data-reservaplus-daydetail-reserve]");
    if (reserveBtn) {
      reserveBtn.addEventListener("click", function () {
        var dateKey =
          detail.getAttribute("data-reservaplus-daydetail-datekey") ||
          toDateKey(new Date());
        closeDayDetail();
        openQuickReservation(dateKey);
      });
    }
  }

  // --- Quick reservation modal init ---

  function initQuickReservationModal() {
    document
      .querySelectorAll("[data-reservaplus-modal-close]")
      .forEach(function (button) {
        button.addEventListener("click", closeQuickReservation);
      });
  }

  // --- Real-time availability checker ---

  function setAvailabilityState(box, state, message) {
    box.className = "reservaplus-availability reservaplus-field-wide is-" + state;
    var icons = {
      checking: "ti ti-loader-2",
      available: "ti ti-circle-check",
      busy: "ti ti-alert-triangle",
      error: "ti ti-help-circle",
      info: "ti ti-info-circle",
    };
    box.innerHTML =
      "<i class='" + (icons[state] || icons.error) + "'></i><span></span>";
    box.querySelector("span").textContent = message;
    box.hidden = false;
  }

  function describeConflicts(conflicts) {
    if (!conflicts || !conflicts.length) return "";
    var parts = conflicts.map(function (c) {
      var time = "";
      if (c.begin && c.end) {
        time = formatTime(c.begin) + "–" + formatTime(c.end);
      }
      if (c.type === "block") {
        return "bloqueio" + (time ? " " + time : "");
      }
      return "reserva" + (time ? " " + time : "");
    });
    return parts.join(", ");
  }

  function initAvailabilityChecker() {
    document
      .querySelectorAll("[data-reservaplus-availability-form]")
      .forEach(function (form) {
        var url = form.getAttribute("data-availability-url");
        var box = form.querySelector("[data-reservaplus-availability]");
        var itemField = form.querySelector(
          "[name='reservationitems_id'], [name='reservationitems_id[]']"
        );
        var beginField = form.querySelector("[name='begin']");
        var endField = form.querySelector("[name='end']");
        if (!url || !box || !itemField || !beginField || !endField) return;

        var timer = null;
        var token = 0;

        function selectedItemIds() {
          if (itemField.multiple) {
            return Array.prototype.slice
              .call(itemField.selectedOptions)
              .map(function (o) {
                return parseInt(o.value, 10) || 0;
              })
              .filter(function (v) {
                return v > 0;
              });
          }
          var v = parseInt(itemField.value, 10) || 0;
          return v > 0 ? [v] : [];
        }

        function check() {
          var ids = selectedItemIds();
          var begin = beginField.value;
          var end = endField.value;

          if (ids.length === 0 || !begin || !end) {
            box.hidden = true;
            return;
          }
          if (new Date(begin) >= new Date(end)) {
            setAvailabilityState(box, "busy", "O fim deve ser depois do início.");
            return;
          }
          if (ids.length > 1) {
            setAvailabilityState(
              box,
              "info",
              ids.length +
                " itens selecionados — a disponibilidade de cada um é verificada ao reservar."
            );
            return;
          }
          var itemId = ids[0];

          setAvailabilityState(box, "checking", "Verificando disponibilidade…");
          var current = ++token;
          var params = new URLSearchParams({
            reservationitems_id: String(itemId),
            begin: begin,
            end: end,
          });

          fetch(url + "?" + params.toString(), { credentials: "same-origin" })
            .then(function (r) {
              return r.json();
            })
            .then(function (data) {
              if (current !== token) return; // resposta obsoleta
              if (data.available) {
                setAvailabilityState(
                  box,
                  "available",
                  "Disponível neste horário."
                );
              } else {
                var desc = describeConflicts(data.conflicts);
                setAvailabilityState(
                  box,
                  "busy",
                  "Indisponível" + (desc ? " (" + desc + ")" : "") + "."
                );
              }
            })
            .catch(function () {
              if (current !== token) return;
              setAvailabilityState(
                box,
                "error",
                "Não foi possível verificar agora."
              );
            });
        }

        function schedule() {
          if (timer) window.clearTimeout(timer);
          timer = window.setTimeout(check, 350);
        }

        itemField.addEventListener("change", schedule);
        beginField.addEventListener("change", schedule);
        endField.addEventListener("change", schedule);
        beginField.addEventListener("input", schedule);
        endField.addEventListener("input", schedule);

        check(); // estado inicial
      });
  }

  // --- Global keyboard handler ---

  document.addEventListener("keydown", function (event) {
    if (event.key !== "Escape") return;
    var detail = document.querySelector("[data-reservaplus-daydetail]");
    if (detail && !detail.hidden) {
      closeDayDetail();
      return;
    }
    closeQuickReservation();
  });

  // --- Atalhos de duração + seleção de itens por categoria ---

  function initReservationFormTools() {
    function pad(n) {
      return (n < 10 ? "0" : "") + n;
    }
    function fmtLocal(d) {
      return (
        d.getFullYear() +
        "-" +
        pad(d.getMonth() + 1) +
        "-" +
        pad(d.getDate()) +
        "T" +
        pad(d.getHours()) +
        ":" +
        pad(d.getMinutes())
      );
    }
    function parseHM(value, fallbackH, fallbackM) {
      var m = /^(\d{1,2}):(\d{2})/.exec(value || "");
      return m ? [parseInt(m[1], 10), parseInt(m[2], 10)] : [fallbackH, fallbackM];
    }

    // Atalhos de duração
    document
      .querySelectorAll("[data-reservaplus-presets]")
      .forEach(function (bar) {
        var form = bar.closest("form");
        if (!form) return;
        var beginField = form.querySelector("[name='begin']");
        var endField = form.querySelector("[name='end']");
        if (!beginField || !endField) return;

        var bhStart = parseHM(bar.getAttribute("data-bh-start"), 8, 0);
        var bhEnd = parseHM(bar.getAttribute("data-bh-end"), 18, 0);

        function baseDate() {
          var v = beginField.value ? new Date(beginField.value) : null;
          if (v && !isNaN(v.getTime())) return v;
          var n = new Date();
          n.setMinutes(0, 0, 0);
          n.setHours(n.getHours() + 1);
          return n;
        }
        function setRange(begin, end) {
          beginField.value = fmtLocal(begin);
          endField.value = fmtLocal(end);
          beginField.dispatchEvent(new Event("change", { bubbles: true }));
          endField.dispatchEvent(new Event("change", { bubbles: true }));
        }

        bar.querySelectorAll("button[data-duration]").forEach(function (btn) {
          btn.addEventListener("click", function () {
            var mins = parseInt(btn.getAttribute("data-duration"), 10) || 60;
            var b = baseDate();
            setRange(b, new Date(b.getTime() + mins * 60000));
          });
        });
        bar.querySelectorAll("button[data-period]").forEach(function (btn) {
          btn.addEventListener("click", function () {
            var b = baseDate();
            var e = new Date(b.getTime());
            var period = btn.getAttribute("data-period");
            if (period === "morning") {
              b.setHours(8, 0, 0, 0);
              e.setHours(12, 0, 0, 0);
            } else if (period === "afternoon") {
              b.setHours(13, 0, 0, 0);
              e.setHours(18, 0, 0, 0);
            } else {
              b.setHours(bhStart[0], bhStart[1], 0, 0);
              e.setHours(bhEnd[0], bhEnd[1], 0, 0);
            }
            setRange(b, e);
          });
        });
      });

    // Seleção de itens por categoria
    document
      .querySelectorAll("[data-reservaplus-item-tools]")
      .forEach(function (tools) {
        var form = tools.closest("form");
        if (!form) return;
        var select = form.querySelector("select[name='reservationitems_id[]']");
        if (!select) return;
        var catSelect = tools.querySelector("[data-reservaplus-cat]");

        function fireChange() {
          select.dispatchEvent(new Event("change", { bubbles: true }));
        }
        function setAll(value) {
          Array.prototype.forEach.call(select.options, function (o) {
            o.selected = value;
          });
          fireChange();
        }

        var selCatBtn = tools.querySelector("[data-reservaplus-select-cat]");
        if (selCatBtn && catSelect) {
          selCatBtn.addEventListener("click", function () {
            var cat = catSelect.value;
            if (!cat) return;
            Array.prototype.forEach.call(select.options, function (o) {
              var group =
                o.parentElement && o.parentElement.tagName === "OPTGROUP"
                  ? o.parentElement.label
                  : "";
              if (group === cat) o.selected = true;
            });
            fireChange();
          });
        }
        var allBtn = tools.querySelector("[data-reservaplus-select-all]");
        if (allBtn)
          allBtn.addEventListener("click", function () {
            setAll(true);
          });
        var clearBtn = tools.querySelector("[data-reservaplus-clear-sel]");
        if (clearBtn)
          clearBtn.addEventListener("click", function () {
            setAll(false);
          });
      });

    // Buscar horários livres do dia (janelas em que o(s) item(ns) estão livres)
    document
      .querySelectorAll("[data-reservaplus-find-slots]")
      .forEach(function (btn) {
        var form = btn.closest("form");
        if (!form) return;
        var url = form.getAttribute("data-slots-url");
        var box = form.querySelector("[data-reservaplus-slots]");
        var beginField = form.querySelector("[name='begin']");
        var endField = form.querySelector("[name='end']");
        var select = form.querySelector("select[name='reservationitems_id[]']");
        if (!url || !box || !beginField || !endField || !select) return;

        function selectedIds() {
          return Array.prototype.slice
            .call(select.selectedOptions)
            .map(function (o) {
              return parseInt(o.value, 10) || 0;
            })
            .filter(function (v) {
              return v > 0;
            });
        }
        function currentDurationMin() {
          var b = beginField.value ? new Date(beginField.value) : null;
          var e = endField.value ? new Date(endField.value) : null;
          if (b && e && !isNaN(b.getTime()) && !isNaN(e.getTime()) && e > b) {
            return Math.round((e.getTime() - b.getTime()) / 60000);
          }
          return 60;
        }

        btn.addEventListener("click", function () {
          var ids = selectedIds();
          box.hidden = false;
          if (ids.length === 0) {
            box.innerHTML =
              "<span class='reservaplus-slots-empty'>Selecione ao menos um item.</span>";
            return;
          }
          var date = beginField.value
            ? beginField.value.slice(0, 10)
            : new Date().toISOString().slice(0, 10);
          var params = new URLSearchParams();
          ids.forEach(function (id) {
            params.append("reservationitems_id[]", String(id));
          });
          params.append("date", date);

          box.innerHTML =
            "<span class='reservaplus-slots-loading'>Buscando horários livres…</span>";
          fetch(url + "?" + params.toString(), { credentials: "same-origin" })
            .then(function (r) {
              return r.json();
            })
            .then(function (data) {
              var slots = (data && data.slots) || [];
              if (slots.length === 0) {
                box.innerHTML =
                  "<span class='reservaplus-slots-empty'>Nenhuma janela livre nesse dia.</span>";
                return;
              }
              box.innerHTML = "";
              var dur = currentDurationMin();
              slots.forEach(function (s) {
                var chip = document.createElement("button");
                chip.type = "button";
                chip.className =
                  "btn btn-sm btn-outline-success reservaplus-slot-chip";
                chip.textContent = s.label;
                chip.addEventListener("click", function () {
                  var start = new Date(s.begin_local);
                  var endWin = new Date(s.end_local);
                  var end = new Date(start.getTime() + dur * 60000);
                  if (end > endWin) end = endWin;
                  beginField.value = s.begin_local;
                  endField.value = fmtLocal(end);
                  beginField.dispatchEvent(new Event("change", { bubbles: true }));
                  endField.dispatchEvent(new Event("change", { bubbles: true }));
                });
                box.appendChild(chip);
              });
            })
            .catch(function () {
              box.innerHTML =
                "<span class='reservaplus-slots-empty'>Não foi possível buscar agora.</span>";
            });
        });
      });
    // Recorrência: mostra/oculta campos conforme o tipo escolhido
    document
      .querySelectorAll("[data-reservaplus-recurrence]")
      .forEach(function (rec) {
        var form = rec.closest("form");
        var typeSel = rec.querySelector("[data-reservaplus-rec-type]");
        var weekdays = rec.querySelector("[data-reservaplus-rec-weekdays]");
        var endBox = rec.querySelector("[data-reservaplus-rec-end]");
        var endMode = rec.querySelector("[data-reservaplus-rec-endmode]");
        var countBox = rec.querySelector("[data-reservaplus-rec-count]");
        var untilBox = rec.querySelector("[data-reservaplus-rec-until]");
        var beginField = form ? form.querySelector("[name='begin']") : null;
        if (!typeSel) return;

        function syncType() {
          var t = typeSel.value;
          if (endBox) endBox.hidden = t === "none";
          if (weekdays) {
            weekdays.hidden = t !== "weekly";
            if (
              t === "weekly" &&
              beginField &&
              beginField.value &&
              !weekdays.querySelector("input:checked")
            ) {
              var dow = new Date(beginField.value).getDay();
              var cb = weekdays.querySelector("input[value='" + dow + "']");
              if (cb) cb.checked = true;
            }
          }
        }
        function syncEndMode() {
          if (!endMode) return;
          var isCount = endMode.value === "count";
          if (countBox) countBox.hidden = !isCount;
          if (untilBox) untilBox.hidden = isCount;
        }
        typeSel.addEventListener("change", syncType);
        if (endMode) endMode.addEventListener("change", syncEndMode);
        syncType();
        syncEndMode();
      });
  }

  // --- Bootstrap ---

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () {
      initCalendars();
      initDayDetailModal();
      initQuickReservationModal();
      initAvailabilityChecker();
      initReservationFormTools();
    });
  } else {
    initCalendars();
    initDayDetailModal();
    initQuickReservationModal();
    initAvailabilityChecker();
    initReservationFormTools();
  }
})();
