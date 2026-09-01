# Referenzen — Standards, Fundstellen, Hintergrundwissen

Dieser Ordner ordnet die Spezifikationen, auf denen davServices beruht, nach den **drei Protokollen**, die die Bibliothek bedient:

| Teil | Protokoll | Wofür |
|---|---|---|
| **A** | WebDAV | Dateien und Ordner |
| **B** | CalDAV | Kalender, Termine, Aufgaben |
| **C** | CardDAV | Adressbücher und Kontakte |

Davor steht, was für alle drei gilt; danach Gremien und Sichtbarkeit.

---

## Zwei Grundregeln

**Bei Widersprüchen zwischen unseren Anforderungen und einem RFC gewinnt der RFC.** Fällt so ein Widerspruch auf, ist er zu melden — er ist ein Fehler in unserer Spezifikation, nicht im Normtext.

**Die Textfassung eines RFC ist die maßgebliche.** PDF und HTML sind bequemer zu lesen; bei Abweichungen gilt der Text. Das steht in den RFCs selbst so.

## Beigelegte Dateien

| Datei | Inhalt |
|---|---|
| `rfc4918.pdf` | WebDAV-Kern, zum Lesen und Annotieren |
| `rfc4791.pdf` | CalDAV, zum Lesen und Annotieren |
| `download-rfcs.sh` | Holt 26 Spezifikationen als Text nach `rfc/` |

```bash
./download-rfcs.sh
```

---

# Gemeinsame Grundlagen

Diese gelten für alle drei Protokolle und werden in den Teilen A bis C nicht wiederholt.

## Das Fundament: HTTP

WebDAV ist keine eigenständige Sprache, sondern eine Erweiterung von HTTP. Alles, was HTTP über Statuscodes, konditionale Anfragen, ETags, `Range` und Verbindungsbehandlung sagt, gilt unverändert.

| RFC | Inhalt | Wofür man es braucht |
|---|---|---|
| [9110](https://www.rfc-editor.org/rfc/rfc9110.txt) | HTTP Semantics | Statuscodes, Methoden, konditionale Anfragen — §13.2.2 enthält die Auswertungsreihenfolge von `If-Match` und Verwandten |
| [9111](https://www.rfc-editor.org/rfc/rfc9111.txt) | HTTP Caching | Nur am Rande: DAV-Antworten sind meist nicht cachefähig |
| [9112](https://www.rfc-editor.org/rfc/rfc9112.txt) | HTTP/1.1 | Nachrichtenaufbau, `Transfer-Encoding`, `Expect: 100-continue` |
| [7617](https://www.rfc-editor.org/rfc/rfc7617.txt) | HTTP Basic Authentication | Das Verfahren, das praktisch alle DAV-Clients verwenden |

**Wichtig:** RFC 4918 und RFC 4791 verweisen noch auf RFC 2616, den abgelösten HTTP/1.1-Text. Bei Zweifelsfragen zur HTTP-Semantik gilt RFC 9110.

## Zugriffskontrolle

Alle drei Protokolle setzen dasselbe Rechtemodell voraus.

| RFC | Inhalt |
|---|---|
| [3744](https://www.rfc-editor.org/rfc/rfc3744.txt) | WebDAV Access Control Protocol |

| Frage | Fundstelle |
|---|---|
| Welche Privilegien gibt es, wie aggregieren sie? | §3 |
| Wie ist `DAV:acl` aufgebaut? | §5.5 |
| Was ist ein Principal, was eine Principal-Kollektion? | §4, §5.8 |
| Wie funktioniert `principal-property-search`? | §9.4 |
| Welches Privileg braucht welche Methode? | Anhang B |

**Was man wissen muss:** Privilegien sind hierarchisch. `DAV:all` enthält `DAV:read` und `DAV:write`; `DAV:write` enthält `write-content`, `write-properties`, `bind` und `unbind`. Eine Implementierung, die diese Aggregation nicht korrekt auflöst, gewährt entweder zu viel oder zu wenig — beides fällt erst spät auf.

## Synchronisation

| RFC | Inhalt |
|---|---|
| [6578](https://www.rfc-editor.org/rfc/rfc6578.txt) | Collection Synchronization for WebDAV |

| Frage | Fundstelle |
|---|---|
| Wie funktioniert `sync-collection`? | §3 |
| Wann liefere ich `DAV:valid-sync-token`? | §3.2 |
| Wie kürze ich eine zu große Antwort? | §3.6 |

**Was man wissen muss:** Der Sync-Token ist für den Client undurchsichtig — er darf beliebig aufgebaut sein, muss aber je Kollektion eindeutig und monoton sein. Ein Client mit zu altem Token muss `403` mit `DAV:valid-sync-token` erhalten; dann führt er einen Vollabgleich durch. Antwortet man stattdessen mit einer leeren Liste, hält er die Kollektion für unverändert und bemerkt Löschungen nie.

## Diensterkennung

| RFC | Inhalt |
|---|---|
| [6764](https://www.rfc-editor.org/rfc/rfc6764.txt) | Locating Services for Calendaring and Contacts |
| [8615](https://www.rfc-editor.org/rfc/rfc8615.txt) | Well-Known Uniform Resource Identifiers |

| Frage | Fundstelle |
|---|---|
| Wie findet ein Client den Server aus einer E-Mail-Adresse? | RFC 6764 §6 (DNS SRV und TXT) |
| Was liegt unter `/.well-known/caldav`? | RFC 6764 §5 |

**Was man wissen muss:** Ohne `.well-known` muss jeder Nutzer den vollständigen DAV-Pfad von Hand eintippen. Das ist die häufigste Hürde bei selbst gehosteten Servern. Die Weiterleitung dorthin ist die **einzige** Stelle im Projekt, an der eine externe Umleitung zulässig ist — überall sonst schadet sie DAV-Clients.

## Kollationen

| RFC | Inhalt |
|---|---|
| [4790](https://www.rfc-editor.org/rfc/rfc4790.txt) | Internet Application Protocol Collation Registry |
| [5051](https://www.rfc-editor.org/rfc/rfc5051.txt) | `i;unicode-casemap` |

**Was man wissen muss:** CalDAV und CardDAV schreiben vor, dass Textvergleiche nach einer benannten Kollation erfolgen. Pflicht sind `i;octet` (bytegenau) und `i;ascii-casemap` (Großschreibung nur für A–Z ignoriert). `i;unicode-casemap` ist optional, wird aber von Clients erwartet, sobald Umlaute im Spiel sind. Alle Vergleiche sind **Teilzeichenketten**-Vergleiche, keine Gleichheitsprüfungen.

## Kontingente und erweitertes MKCOL

| RFC | Inhalt | Anmerkung |
|---|---|---|
| [4331](https://www.rfc-editor.org/rfc/rfc4331.txt) | Quota and Size Properties | `quota-available-bytes`, `quota-used-bytes` |
| [5689](https://www.rfc-editor.org/rfc/rfc5689.txt) | Extended MKCOL | Nötig, um Adressbücher anzulegen — CardDAV hat kein eigenes `MKADDRESSBOOK` |

---

# Teil A — WebDAV: Dateien und Ordner

## Was es ist

WebDAV erweitert HTTP um das, was zum Bearbeiten von Dateien über das Netz fehlt: Eigenschaften an Ressourcen, Verzeichnisse als eigenständiges Konzept, Kopieren und Verschieben, sowie Sperren gegen gleichzeitiges Überschreiben.

In davServices ist WebDAV **das Fundament** — CalDAV und CardDAV sind Aufsätze darauf. Wer den Kern nicht korrekt umsetzt, bekommt die beiden anderen nicht zum Laufen.

## Normative Grundlage

| RFC | Inhalt | Verbindlichkeit |
|---|---|---|
| [4918](https://www.rfc-editor.org/rfc/rfc4918.txt) | HTTP Extensions for WebDAV — löst RFC 2518 ab | **MUSS vollständig** |
| [3744](https://www.rfc-editor.org/rfc/rfc3744.txt) | Access Control | MUSS |
| [4331](https://www.rfc-editor.org/rfc/rfc4331.txt) | Quota | MUSS |
| [5689](https://www.rfc-editor.org/rfc/rfc5689.txt) | Extended MKCOL | MUSS |
| [6578](https://www.rfc-editor.org/rfc/rfc6578.txt) | Collection Synchronization | MUSS |
| [3253](https://www.rfc-editor.org/rfc/rfc3253.txt) | Versioning (DeltaV) | **nur** §3.6, die Definition von `REPORT` |
| [5842](https://www.rfc-editor.org/rfc/rfc5842.txt) | BIND — mehrere URLs auf dieselbe Ressource | nicht umgesetzt, zur Kenntnis |
| [5323](https://www.rfc-editor.org/rfc/rfc5323.txt) | SEARCH (DASL) | nicht umgesetzt, zur Kenntnis |

## Was man wissen muss

**Das Ressourcenmodell.** Es gibt Kollektionen und Nicht-Kollektionen. Eine Kollektion ist kein Verzeichnis im Dateisystemsinn, sondern eine Ressource mit `DAV:collection` im `resourcetype`. Der Unterschied wird bei `DELETE` und `MOVE` mit `Depth` relevant.

**Lebende und tote Properties.** Lebende berechnet der Server (`getcontentlength`, `getetag`, `resourcetype`); tote speichert er nur ab, ohne den Inhalt zu deuten. Eine tote Property kann beliebiges XML enthalten und muss verlustfrei zurückgegeben werden — auch verschachtelte Elemente mit fremden Namensräumen.

**`207 Multi-Status`.** Die zentrale Antwortform. Sie enthält je Ressource einen eigenen Statuscode; die Gesamtantwort ist immer `207`, selbst wenn alle Teilantworten Fehler sind. Ein Client, der nur den äußeren Status auswertet, bekommt falsche Ergebnisse — deshalb muss die Struktur exakt stimmen.

**Die Compliance-Klassen.** Der `DAV`-Header nennt sie: `1` für den Kern, `2` für Locking, `3` für RFC 4918 statt 2518. Dazu benannte Fähigkeiten wie `access-control`, `extended-mkcol`, `calendar-access`, `addressbook`. Clients entscheiden anhand dieses Headers, was sie überhaupt versuchen.

**Der `If`-Header ist die schwierigste Einzelstelle des Protokolls.** Er kombiniert Lock-Tokens und ETags zu getaggten und ungetaggten Listen mit optionalem `Not`; die Auswertung ist eine Konjunktion von Disjunktionen. Er wird selten von Hand getestet, weil ihn nur wenige Clients erzeugen — bis einer es tut.

**Preconditions sind XML, nicht nur Statuscodes.** Schlägt eine Vorbedingung fehl, genügt kein blanker `403`. Die Antwort muss ein `DAV:error`-Element mit dem passenden Kindelement enthalten, damit der Client weiß, was schiefging.

## Frage → Fundstelle

| Frage | Fundstelle in RFC 4918 |
|---|---|
| Wie muss `PROPFIND` antworten? | §9.1 |
| Welche Statuscodes gehören in `propstat`? | §9.1.2 |
| Was bedeutet `Depth` je Methode? | §9.1 (PROPFIND), §9.6 (DELETE), §9.8/§9.9 (COPY/MOVE), §9.10 (LOCK) |
| **Wie ist der `If`-Header aufgebaut und auszuwerten?** | **§10.4** |
| Wie funktionieren `LOCK` und `UNLOCK`? | §6, §9.10, §9.11 |
| Was ist ein Lock-Token, wie lange gilt es? | §6.5, §10.7 (`Timeout`) |
| Wann liefere ich `207`? | §13 |
| Welche Statuscodes definiert WebDAV neu? | §11 (`207`, `422`, `423`, `424`, `507`) |
| Wie verhalten sich `COPY` und `MOVE` bei `Overwrite`? | §9.8, §9.9 |
| Welche Live-Properties sind Pflicht? | §15 |
| Wie muss `PROPPATCH` sich verhalten, wenn eine Property scheitert? | §9.2 — atomar, `424` für die übrigen |
| Wie ist XML zu verarbeiten, was ist verboten? | §17, §20.6 (Entity-Angriffe) |
| Welche Sicherheitsfragen stellt WebDAV? | §20 |

## Links

| Link | Was dort steht |
|---|---|
| [rfc-editor.org/rfc/rfc4918.txt](https://www.rfc-editor.org/rfc/rfc4918.txt) | Maßgebliche Textfassung |
| [datatracker.ietf.org/doc/html/rfc4918](https://datatracker.ietf.org/doc/html/rfc4918) | HTML mit Querverweisen, Errata-Anzeige und Liste aktualisierender Dokumente. Für die tägliche Arbeit angenehmer als der Text. |
| [webdav.org/specs/rfc4918.pdf](http://www.webdav.org/specs/rfc4918.pdf) | PDF zum Drucken und Annotieren |
| [webdav.org/specs/](http://www.webdav.org/specs/) | Die gesamte WebDAV-Spezifikationsfamilie auf einer Seite, jeweils als Text, PDF und XML. Nützlich, wenn man die RFC-Nummer nicht kennt. |
| [webdav.org/other/faq.html](http://www.webdav.org/other/faq.html) | Die WebDAV-FAQ. Beste erste Anlaufstelle für alle im Team, die noch nicht mit DAV gearbeitet haben: was WebDAV ist, wie es sich zu HTTP verhält, was die Klassen bedeuten. |
| [webdav.org](http://www.webdav.org/) | Die historische Sammelstelle rund um WebDAV. Inhaltlich weitgehend auf dem Stand der frühen 2000er; Spezifikationsseite, FAQ und Werkzeuge sind weiterhin nützlich. |

## Werkzeuge

| Werkzeug | Zweck |
|---|---|
| [Litmus](http://www.webdav.org/neon/litmus/) | Referenz-Testsuite für WebDAV. Die Klassen `basic`, `copymove`, `props` und `locks` sind Abnahmekriterium. |
| [cadaver](http://www.webdav.org/cadaver/) | Kommandozeilen-DAV-Client. Um von Hand nachzusehen, was der Server tatsächlich antwortet. |
| `curl -X PROPFIND` | Für schnelle Einzelprüfungen völlig ausreichend. |

## Typische Stolpersteine

| Symptom | Ursache |
|---|---|
| Synchronisation scheitert bei manchen Dateien | Apaches `MultiViews` verändert bei Dateien ohne Endung die aufgelöste Ressource |
| Niemand kann sich anmelden, kein Fehler im Log | Der `Authorization`-Header erreicht PHP unter FastCGI nicht |
| `PROPFIND` kommt nie an | Ein natives `mod_dav` fängt die Anfrage vor PHP ab |
| Clients verlieren Daten | Eine externe Umleitung im Front Controller; DAV-Clients folgen ihr bei `PROPFIND`, `PUT` und `MOVE` unzuverlässig |
| Clients löschen lokal alles | Der Server antwortet mit `404` statt `503`, etwa im Wartungsmodus |

---

# Teil B — CalDAV: Kalender, Termine, Aufgaben

## Was es ist

CalDAV modelliert einen Kalender als WebDAV-Kollektion, deren Kindressourcen jeweils ein iCalendar-Objekt enthalten. Dazu kommen Suchabfragen über `REPORT`, eine eigene Berechtigung für Belegtzeiten und mehrere Kollektions-Properties für Grenzwerte.

CalDAV ist der **umfangreichste Teil** des Vorhabens — nicht wegen des Protokolls, sondern wegen des Datenformats darunter.

## Normative Grundlage

| RFC | Inhalt | Verbindlichkeit |
|---|---|---|
| [4791](https://www.rfc-editor.org/rfc/rfc4791.txt) | CalDAV | **MUSS vollständig** |
| [5545](https://www.rfc-editor.org/rfc/rfc5545.txt) | iCalendar — löst RFC 2445 ab | **MUSS vollständig** |
| [5546](https://www.rfc-editor.org/rfc/rfc5546.txt) | iTIP — Einladungsabläufe | MUSS |
| [6638](https://www.rfc-editor.org/rfc/rfc6638.txt) | CalDAV Scheduling | MUSS |
| [7986](https://www.rfc-editor.org/rfc/rfc7986.txt) | Neue iCalendar-Properties (`COLOR`, `IMAGE`, `CONFERENCE`, `NAME`) | SOLL |
| [6047](https://www.rfc-editor.org/rfc/rfc6047.txt) | iMIP — iTIP über E-Mail | v1.1 |
| [7529](https://www.rfc-editor.org/rfc/rfc7529.txt) | Nicht-gregorianische Wiederholungen | KANN |
| [7953](https://www.rfc-editor.org/rfc/rfc7953.txt) | `VAVAILABILITY` | KANN |
| [8607](https://www.rfc-editor.org/rfc/rfc8607.txt) | Managed Attachments | KANN |

## Was man wissen muss

**Ein Objekt, eine UID, ein Komponententyp.** Eine Kalenderressource darf nur Komponenten **eines** Typs enthalten (plus `VTIMEZONE`), nur **eine** `UID`, und keine `METHOD`-Eigenschaft. Die `UID` muss innerhalb der Kollektion eindeutig sein. (RFC 4791 §4.1)

**Die gesamte Wiederholungsreihe liegt in einer Ressource.** Auf die Hauptkomponente folgen die überschriebenen Instanzen mit `RECURRENCE-ID` — alle in derselben Datei. Das vermeidet die Frage, wie viele Instanzen man speichert, verlagert die Arbeit aber vollständig auf den Server: Er muss die Reihe entfalten, um Zeitraumfragen zu beantworten. (§3.2, §4.1)

**Die Zeitraumfilterung ist der Kern.** RFC 4791 §9.9 enthält Tabellen je Komponententyp, die festlegen, wann eine Komponente einen Zeitraum überlappt. Bei `VTODO` sind es **acht Fallunterscheidungen**, abhängig davon, welche der Eigenschaften `DTSTART`, `DURATION`, `DUE`, `COMPLETED` und `CREATED` vorhanden sind. Bei `VEVENT` hängt es davon ab, ob `DTEND` oder `DURATION` gesetzt ist und ob `DTSTART` ein Datum oder ein Zeitpunkt ist — fehlt beides, gilt ein Tag bei Datum und null Sekunden bei Zeitpunkt.

Diese Tabellen lassen sich aus keiner Zusammenfassung rekonstruieren. Sie sind vor der Umsetzung vollständig zu lesen.

**Floating Time.** iCalendar kennt Datums- und Zeitangaben ohne Zeitzone. Sie bedeuten „dieselbe Uhrzeit, egal wo" und müssen für Zeitraumvergleiche erst aufgelöst werden — gegen `CALDAV:timezone` aus der Anfrage, ersatzweise gegen `CALDAV:calendar-timezone` der Kollektion. (§7.3)

**Starke ETags sind Pflicht — mit einer Ausnahme, die alles bestimmt.** RFC 4791 §5.3.4 verlangt einen starken ETag auf jeder Kalenderressource. Speichert der Server aber etwas anderes, als der Client gesendet hat, **darf er keinen starken ETag zurückgeben**. Genau daraus folgt unsere Regel, bei serverseitiger Änderung gar keinen ETag zu senden: Der Client muss dann neu abrufen, statt mit einer veralteten lokalen Fassung weiterzuarbeiten.

**`read-free-busy` ist eine eigene Berechtigung.** Sie ist in `DAV:read` enthalten, muss aber auch **ohne** `DAV:read` vergeben werden können. Wer sie hat, darf `free-busy-query` stellen, aber weder `GET` noch `PROPFIND` — er sieht Belegtzeiten ohne Details. (§6.1.1)

**Grenzwerte sind Properties.** `max-resource-size`, `min-date-time`, `max-date-time`, `max-instances`, `max-attendees-per-instance`. Sie sind laut RFC optional, aber ohne sie kann ein Client eine Wiederholungsregel hochladen, die Milliarden Instanzen erzeugt. RFC 4791 §11 nennt genau das als Denial-of-Service-Beispiel.

## Frage → Fundstelle

| Frage | Fundstelle in RFC 4791 |
|---|---|
| Was darf in einer Kalender-Kollektion liegen? | §4.1, §4.2 |
| Warum nur ein Komponententyp je Ressource? | §4.1 |
| Wie wird `MKCALENDAR` beantwortet? | §5.3.1, Statuscodes §5.3.1.1 |
| Welche Preconditions gelten bei `PUT`, `COPY`, `MOVE`? | §5.3.2.1 |
| Wie behandle ich `X-`-Eigenschaften? | §5.3.3 |
| Warum starke ETags, und wann keine? | §5.3.4 |
| Wie funktioniert `read-free-busy`? | §6.1.1 |
| Wo liegt `calendar-home-set`? | §6.2.1 |
| Wie ist `calendar-query` aufgebaut? | §7.8, XML in §9.5 und §9.7 |
| Wie funktioniert `calendar-multiget`? | §7.9 |
| Wie erzeuge ich `free-busy-query`? | §7.10 — enthält die Zuordnung von `TRANSP` und `STATUS` auf `FBTYPE` |
| **Wie prüfe ich `time-range` genau?** | **§9.9** |
| Was macht `expand` gegenüber `limit-recurrence-set`? | §9.6.5 gegen §9.6.6 |
| Wie schränke ich `calendar-data` ein? | §9.6 und §9.6.1 bis §9.6.4 |
| Welche Kollationen sind Pflicht? | §7.5, Property in §7.5.1 |
| Wie behandle ich Floating Time? | §7.3 |
| Welche Grenzwert-Properties gibt es? | §5.2.5 bis §5.2.9 |
| Wie sollen Clients synchronisieren? | §8.2 — Empfehlungen, keine Pflicht, aber aufschlussreich für das Serververhalten |
| Welche Sicherheitsfragen stellt CalDAV? | §11 |

| Frage | Fundstelle in RFC 5545 |
|---|---|
| **Wie funktioniert `RRULE` im Detail?** | **§3.3.10** |
| Wie wird gefaltet und maskiert? | §3.1 |
| Wie wirkt `RANGE=THISANDFUTURE`? | §3.8.4.4 |
| Wie ist `VTIMEZONE` aufgebaut? | §3.6.5 |
| Welche Eigenschaften haben `VEVENT`, `VTODO`, `VJOURNAL`? | §3.6.1 bis §3.6.3 |
| Wie ist `VALARM` aufgebaut? | §3.6.6 |

## Links

| Link | Was dort steht |
|---|---|
| [ietf.org/rfc/rfc4791.txt](http://www.ietf.org/rfc/rfc4791.txt) | Maßgebliche Textfassung |
| [datatracker.ietf.org/doc/html/rfc4791](https://datatracker.ietf.org/doc/html/rfc4791) | HTML mit Querverweisen und Errata |
| [devguide.calconnect.org/caldav/](https://devguide.calconnect.org/caldav/) | CalDAV praktisch erklärt: Abläufe, Beispiele, Client-Eigenheiten |
| [devguide.calconnect.org](https://devguide.calconnect.org/) | **Der praktisch wichtigste Link dieses Dokuments.** Kein Normtext, sondern Kochbuch — Wiederholungsregeln, Zeitzonen, Synchronisation, Aufgaben. Behandelt genau die Stellen, an denen die RFCs schweigen und die Praxis zuschlägt. |
| [calconnect.org](https://www.calconnect.org/) | Das Calendaring and Scheduling Consortium. Hersteller stimmen sich hier ab und veranstalten Interoperabilitätstests. |
| [datatracker.ietf.org/wg/calext/](https://datatracker.ietf.org/wg/calext/) | Die aktive Arbeitsgruppe. Hier entstehen die Erweiterungen von morgen — derzeit JSCalendar und Aufgaben-Erweiterungen für `VTODO`. |

## Werkzeuge

| Werkzeug | Zweck |
|---|---|
| [CalDAVTester](https://github.com/apple/ccs-caldavtester) | Umfangreiche Testsuite von Apple für CalDAV und CardDAV. Abnahmekriterium. |
| Reale Clients | iOS, macOS, Thunderbird, DAVx⁵, Evolution, eM Client, Outlook CalDAV Synchronizer. Keine Testsuite ersetzt sie. |

## Typische Stolpersteine

| Symptom | Ursache |
|---|---|
| Termine erscheinen um Stunden verschoben | Floating Time gegen die falsche Zeitzone aufgelöst, oder `VTIMEZONE` nicht ausgewertet |
| Ganztägige Termine springen einen Tag | `VALUE=DATE` implizit in einen UTC-Zeitpunkt gewandelt |
| Serientermine fehlen in Zeitraumabfragen | Die Reihe wurde nicht entfaltet, oder die Tabellen aus §9.9 sind unvollständig umgesetzt |
| Endlosschleife bei der Synchronisation | Der Server hat den Inhalt verändert und trotzdem einen ETag zurückgegeben |
| Server hängt bei einem einzigen Upload | Eine `RRULE` ohne `COUNT` und `UNTIL`, ohne Iterationsgrenze entfaltet |
| Apple-Clients zeigen keine Änderungen | `CS:getctag` fehlt — nicht im RFC, aber praktisch erforderlich |

---

# Teil C — CardDAV: Adressbücher und Kontakte

## Was es ist

CardDAV überträgt das CalDAV-Modell auf Kontaktdaten: Ein Adressbuch ist eine WebDAV-Kollektion, deren Kindressourcen jeweils eine vCard enthalten. Suchabfragen laufen wieder über `REPORT`.

CardDAV ist **deutlich einfacher als CalDAV**: keine Wiederholungsregeln, keine Zeitzonen, kein Scheduling. Wer CalDAV umgesetzt hat, hat den größten Teil der Arbeit hinter sich.

## Normative Grundlage

| RFC | Inhalt | Verbindlichkeit |
|---|---|---|
| [6352](https://www.rfc-editor.org/rfc/rfc6352.txt) | CardDAV | **MUSS vollständig** |
| [6350](https://www.rfc-editor.org/rfc/rfc6350.txt) | vCard 4.0 | MUSS |
| [2426](https://www.rfc-editor.org/rfc/rfc2426.txt) | vCard 3.0 | MUSS — Interop-Zwang durch Apple-Clients |
| [2425](https://www.rfc-editor.org/rfc/rfc2425.txt) | MIME Directory Profile — Grundlage beider vCard-Fassungen | MUSS |
| [6473](https://www.rfc-editor.org/rfc/rfc6473.txt), [6474](https://www.rfc-editor.org/rfc/rfc6474.txt), [6715](https://www.rfc-editor.org/rfc/rfc6715.txt), [6869](https://www.rfc-editor.org/rfc/rfc6869.txt) | vCard-Erweiterungen | SOLL |
| [6351](https://www.rfc-editor.org/rfc/rfc6351.txt), [7095](https://www.rfc-editor.org/rfc/rfc7095.txt) | xCard, jCard | KANN |

## Was man wissen muss

**Es gibt kein `MKADDRESSBOOK`.** Anders als bei Kalendern legt man ein Adressbuch mit dem erweiterten `MKCOL` aus RFC 5689 an, mit `CARDDAV:addressbook` im `resourcetype`. Wer RFC 5689 nicht umgesetzt hat, kann keine Adressbücher anlegen. (RFC 6352 §5.2)

**Eine vCard je Ressource, `UID` eindeutig.** Analog zu CalDAV. (§5.1)

**Die Versionsfrage bestimmt das Verhalten.** Clients verlangen unterschiedliche vCard-Fassungen: iOS und macOS arbeiten mit 3.0, modernere Clients mit 4.0. Der Server muss beide beherrschen und umwandeln können.

Daraus folgt eine Feinheit, die man kennen muss: **Der ETag gehört zur gespeicherten Fassung, nicht zur ausgelieferten.** Zwei Clients, die dieselbe Ressource in verschiedenen Versionen abrufen, erhalten denselben ETag bei unterschiedlichen Bytes. Das ist beabsichtigt — der ETag kennzeichnet den Zustand der Ressource, nicht ihre Darstellung. (§6.3.2)

**Kontaktgruppen gibt es zweimal.** In vCard 4.0 über `KIND:group` mit `MEMBER`-Eigenschaften. In 3.0 gibt es das nicht, weshalb Apple `X-ADDRESSBOOKSERVER-KIND:group` und `X-ADDRESSBOOKSERVER-MEMBER` eingeführt hat. **Beide sind zu unterstützen** — ohne die Apple-Variante erscheint das Adressbuch auf iOS als fehlerhaft.

Der Server darf die Mitgliederliste **nicht** prüfen oder bereinigen. Verweise auf nicht vorhandene Kontakte müssen erhalten bleiben; sie können auf Objekte zeigen, die der Client noch nicht hochgeladen hat.

**Bilder sind das Speicherproblem.** `PHOTO` liegt in 3.0 als Base64 vor, in 4.0 als Data-URI. Fotos aus Telefon-Adressbüchern sind selten unter 256 KB. Ohne Verkleinerung wächst der Datenbestand um ein Vielfaches — und EXIF-Daten enthalten regelmäßig GPS-Koordinaten, die man nicht mitverteilen will.

**Textsuche über viele Kontakte braucht einen Index.** `addressbook-query` mit `text-match` über 50.000 Kontakte ist ohne vorbereiteten Suchindex nicht in vertretbarer Zeit zu beantworten — erst recht nicht, wenn die Datenbank über das Netz erreichbar ist.

## Frage → Fundstelle

| Frage | Fundstelle in RFC 6352 |
|---|---|
| Wie wird ein Adressbuch angelegt? | §5.2 |
| Was darf in einer Adressbuch-Kollektion liegen? | §5.1 |
| Welche Preconditions gelten bei `PUT`? | §6.3.2 |
| **Was gilt für ETags bei Versionskonvertierung?** | **§6.3.2** |
| Welche Kollektions-Properties gibt es? | §6.2.1 bis §6.2.3 — `addressbook-description`, `supported-address-data`, `max-resource-size` |
| Wo liegt `addressbook-home-set`? | §7.1.1 |
| Was ist `principal-address`? | §7.1.2 |
| Wie funktioniert `addressbook-query`? | §8.6, XML in §10.3 bis §10.5 |
| Wie funktioniert `addressbook-multiget`? | §8.7 |
| Wie schränke ich `address-data` ein? | §10.4 |
| Wie kombiniere ich Filter mit `allof` und `anyof`? | §10.5 |
| Welche Kollationen sind Pflicht? | §8.3 |
| Wie begrenze ich Ergebnismengen? | §8.6 — `limit`, `nresults` |
| Welche Sicherheitsfragen stellt CardDAV? | §14 |

| Frage | Fundstelle in RFC 6350 (vCard 4.0) |
|---|---|
| Welche Eigenschaften gibt es? | §6 |
| Wie ist `KIND:group` definiert? | §6.1.4 |
| Wie funktioniert `MEMBER`? | §6.6.5 |
| Wie wird `PHOTO` kodiert? | §6.2.4 |
| Wie sind Namen aufgebaut (`N`, `FN`)? | §6.2.1, §6.2.2 |

## Links

| Link | Was dort steht |
|---|---|
| [rfc-editor.org/rfc/rfc6352.txt](https://www.rfc-editor.org/rfc/rfc6352.txt) | Maßgebliche Textfassung |
| [datatracker.ietf.org/doc/html/rfc6352](https://datatracker.ietf.org/doc/html/rfc6352) | HTML mit Querverweisen und Errata |
| [devguide.calconnect.org/CardDAV/](https://devguide.calconnect.org/CardDAV/) | CardDAV praktisch erklärt |
| [devguide.calconnect.org/CardDAV/building-a-carddav-client/](https://devguide.calconnect.org/CardDAV/building-a-carddav-client/) | Anleitung zum Bau eines Clients. Auch für Serverentwickler wertvoll, weil sie beschreibt, was Clients erwarten. |
| [devguide.calconnect.org/CardDAV/libraries/](https://devguide.calconnect.org/CardDAV/libraries/) | Übersicht vorhandener vCard- und CardDAV-Bibliotheken. Nützlich zum Abgleich eigener Entscheidungen. |

**Hinweis:** Es gibt **keine** Entsprechung zu webdav.org für CardDAV — keine `carddav.org`, keine eigene Sammelseite. CardDAV entstand 2011, lange nachdem webdav.org zuletzt gepflegt wurde, und wird dort nicht geführt. Der CalConnect DevGuide ist der beste verfügbare Ersatz.

## Typische Stolpersteine

| Symptom | Ursache |
|---|---|
| iOS zeigt Gruppen nicht an | `X-ADDRESSBOOKSERVER-KIND` bei vCard 3.0 nicht unterstützt |
| Kontakte verlieren Angaben nach der Bearbeitung | Versionskonvertierung verlustbehaftet; unbekannte Eigenschaften verworfen statt als `X-` erhalten |
| Adressbuch lässt sich nicht anlegen | Erweitertes `MKCOL` nach RFC 5689 fehlt |
| Suche dauert Sekunden | Kein Suchindex; `text-match` läuft über alle Objekte |
| Datenbank wächst unerwartet | `PHOTO` unverkleinert gespeichert |
| Umlaut-Suche findet nichts | `i;unicode-casemap` nicht unterstützt, Client nutzt sie trotzdem |

---

# Teil D — Gremien und Sichtbarkeit

## Stand der Arbeitsgruppen

**Die WebDAV-Arbeitsgruppe der IETF ist abgeschlossen.** Ihr Status im Datatracker lautet „concluded"; spätere RFCs sprechen ausdrücklich von der „concluded WebDAV working group" (etwa RFC 5842). Eine Mitgliedschaft ist nicht möglich — es gibt nichts, dem man beitreten könnte.

Die Seite `webdav.org/wg/` beschreibt drei Arbeitsgruppen samt Beitrittsverfahren über Mailinglisten. Sie wurde zuletzt **im Mai 2000** geändert; die dort genannten Listen bei W3C nehmen keine Anmeldungen mehr entgegen.

**Aktiv ist CALEXT.** Diese IETF-Arbeitsgruppe ist ausdrücklich für CalDAV, iCalendar, iTIP, iMIP, vCard und CardDAV zuständig und arbeitet laufend an Erweiterungen. Für davServices ist sie das relevante Gremium.

Für den **WebDAV-Kern selbst** gibt es keine aktive Arbeitsgruppe. RFC 4918 ist stabil; Fehler werden über Errata beim RFC-Editor gemeldet.

| Link | Was dort steht |
|---|---|
| [datatracker.ietf.org/wg/calext/](https://datatracker.ietf.org/wg/calext/) | Die aktive Arbeitsgruppe: Entwürfe, Meilensteine, Vorsitzende |
| [ietf.org/mailman/listinfo/calsify](https://www.ietf.org/mailman/listinfo/calsify) | Ihre Mailingliste. **Eintragen genügt, um mitzuarbeiten** — die IETF kennt keine formale Mitgliedschaft und keine Gebühr. |
| [mailarchive.ietf.org/arch/browse/calsify/](https://mailarchive.ietf.org/arch/browse/calsify/) | Das Archiv. Vor einer Frage lohnt der Blick hinein; vieles ist dort schon diskutiert. |
| [datatracker.ietf.org/wg/webdav/about/](https://datatracker.ietf.org/wg/webdav/about/) | Die WebDAV-Arbeitsgruppe. Status: „concluded". |
| [webdav.org/wg/](http://www.webdav.org/wg/) | Historische Beschreibung, Stand Mai 2000. Nur noch von dokumentarischem Wert. |

## Wie davServices sichtbar wird

Die Projektliste auf `webdav.org/projects/` wurde zuletzt **im April 2003** geändert. Man erkennt es am Inhalt: Sie kennt Jakarta Slide, PyDAV und Adobe GoLive, aber weder sabre/dav noch Nextcloud, Radicale oder Baïkal. Eine Meldung dorthin schadet nicht, dürfte aber unbeantwortet bleiben.

Nach Wirksamkeit geordnet:

| Weg | Aufwand | Wirkung |
|---|---|---|
| **Packagist** | gering | Der Standardweg. Wer in PHP nach einer DAV-Bibliothek sucht, sucht dort. |
| **CalConnect DevGuide** | gering | Führt gepflegte Listen von Implementierungen und Bibliotheken. Beiträge über GitHub, ein Pull Request genügt. Die lebendigste kuratierte Liste im Umfeld. |
| **CALEXT-Mailingliste** | gering | Eintragen, mitlesen, bei Interoperabilitätsfragen beitragen. Wer dort sichtbar mitarbeitet, wird als Implementierer wahrgenommen. |
| **Awesome-Listen auf GitHub** | gering | Etwa `awesome-selfhosted`. Reichweite in der Selbsthosting-Gemeinde. |
| **CalConnect-Mitgliedschaft** | hoch, kostenpflichtig | Zugang zu Interoperabilitätsveranstaltungen, an denen die verbreiteten Clients teilnehmen. Nicht erforderlich, aber die zuverlässigste Art, Interop-Fehler früh zu finden. |
| **webdav.org/projects** | gering | Vollständigkeitshalber. Erwartung: keine Antwort. |
