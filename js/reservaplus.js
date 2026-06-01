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

  // --- Calendar rendering ---

  function renderCalendar(calendar, currentDate, events) {
    var title = calendar.querySelector(".reservaplus-calendar-title");
    var grid = calendar.querySelector(".reservaplus-calendar-grid");
    var weekdays = ["Dom", "Seg", "Ter", "Qua", "Qui", "Sex", "Sáb"];
    var first = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1);
    var start = new Date(first);
    start.setDate(first.getDate() - first.getDay());

    title.textContent = monthLabel(currentDate);
    calendar.classList.remove("is-loading");
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

  function setLoadingState(calendar, currentDate) {
    var title = calendar.querySelector(".reservaplus-calendar-title");
    var grid = calendar.querySelector(".reservaplus-calendar-grid");

    calendar.classList.add("is-loading");
    if (title) {
      title.textContent = monthLabel(currentDate);
    }
    if (grid) {
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

    // Permission-aware delete button (request events only)
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

        function doLoad() {
          var url = calendar.getAttribute("data-events-url");
          var start = new Date(
            currentDate.getFullYear(),
            currentDate.getMonth(),
            1
          );
          var end = new Date(
            currentDate.getFullYear(),
            currentDate.getMonth() + 1,
            0
          );
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
              renderCalendar(calendar, currentDate, loadedEvents);
            })
            .catch(function () {
              loadedEvents = [];
              renderCalendar(calendar, currentDate, []);
            });
        }

        doLoad();

        // Clicking anywhere on a day (including events) opens day detail
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

        var prevBtn = panel.querySelector(".reservaplus-calendar-prev");
        var nextBtn = panel.querySelector(".reservaplus-calendar-next");
        var todayBtn = panel.querySelector(".reservaplus-calendar-today");
        var openTodayBtn = panel.querySelector("[data-reservaplus-open-today]");

        if (prevBtn) {
          prevBtn.addEventListener("click", function () {
            currentDate = new Date(
              currentDate.getFullYear(),
              currentDate.getMonth() - 1,
              1
            );
            doLoad();
          });
        }

        if (nextBtn) {
          nextBtn.addEventListener("click", function () {
            currentDate = new Date(
              currentDate.getFullYear(),
              currentDate.getMonth() + 1,
              1
            );
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
      // Click on backdrop
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

  // --- Bootstrap ---

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () {
      initCalendars();
      initDayDetailModal();
      initQuickReservationModal();
    });
  } else {
    initCalendars();
    initDayDetailModal();
    initQuickReservationModal();
  }
})();
