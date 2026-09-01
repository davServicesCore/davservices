# davServices — Anforderungen an die Protokollbibliothek

**Fassung:** 1.0 · **Stand:** 25.08.2026 · **Status:** normativ

Anforderungen an die Protokollbibliothek für WebDAV, CalDAV und CardDAV. Eigenständiges Produkt, veröffentlicht unter Apache-2.0 über Packagist.

**Schlüsselwörter:** MUSS / DARF NICHT / SOLL / SOLLTE NICHT / KANN gemäß RFC 2119 und RFC 8174.

## Geltungsbereich dieses Dokuments

**Bestandteil:** HTTP-Schicht, XML-Schicht, WebDAV-Kern, Zugriffskontrolle, Synchronisation, CalDAV, CardDAV, iCalendar- und vCard-Verarbeitung, Backend-Schnittstellen, Referenz-Backends.

**Nicht Bestandteil:** Benutzerverwaltung, Authentifizierungsoberflächen, Speicherpolitik und anwendungsspezifische Datenmodelle. Eine einbettende Anwendung stellt diese über die dokumentierten Backend- und Plugin-Schnittstellen bereit.

**Besonderheit:** davServices ist eine eigenständige, öffentliche Protokollbibliothek. Die Anforderungen an Wartbarkeit, Dokumentation und Testabdeckung sind entsprechend streng.

---

## 2. Rahmenbedingungen

| ID | Anforderung |
|---|---|
| R-ENV-01 | Mindestversion PHP 8.2. `declare(strict_types=1)` MUSS in jeder Datei gesetzt sein. |
| R-ENV-02 | Zwingende Extensions: `dom`, `libxml`, `xml`, `xmlreader`, `xmlwriter`, `mbstring`, `json`, `filter`, `hash` — maßgeblich ist `require` in `composer.json`. Optional mit Fallback: `intl`, `openssl`, `pdo` — maßgeblich ist `suggest`. `spl` wird nicht aufgeführt: die Extension ist seit PHP 8 fest eingebaut und nicht abschaltbar. `gd` und `imagick` sind Anwendungsschicht und damit außerhalb des Geltungsbereichs; `curl` entfällt, weil ausgehende Verbindungen über PHP-Streams mit `openssl` laufen und ein zweiter HTTP-Stack der Abhängigkeitsfreiheit widerspräche. |
| R-ENV-03 | Die Anwendung MUSS ohne `intl` voll funktionsfähig sein. |
| R-ENV-04 | Zeitzonendaten MÜSSEN aus der PHP-eigenen Datenbank stammen. Eine mitgelieferte tz-Datenbank DARF NICHT verwendet werden. |
| R-ENV-05 | Die Bibliothek MUSS unter Linux, Windows und macOS lauffähig sein. |
| R-ENV-06 | Die Bibliothek MUSS zur Laufzeit **ohne jedes Paket Dritter** auskommen. Fremdpakete DÜRFEN ausschließlich unter `require-dev` verwendet werden und NICHT in Produktivpfaden referenziert sein. |
| R-ENV-07 | Die Bibliothek MUSS einen eigenen PSR-4-Autoloader mitbringen und ohne Composer einbindbar sein. |
| R-ENV-08 | Wo etablierte Fremdinterfaces existieren (PSR-3, PSR-7, PSR-11), MÜSSEN eigene, strukturgleiche Interfaces definieren. Adapter KÖNNEN separat bereitgestellt werden. |
| R-ENV-09 | Der PHP-Namensraum der Bibliothek lautet `DavServices\`. |
| R-ENV-10 | Die Bibliothek MUSS ohne eigenen HTTP-Server unter jeder unterstützten PHP-SAPI einbettbar sein. |
| R-ENV-11 | **Lizenz von davServices: Apache-2.0.** Namensnennung ist verpflichtend (Copyright-Vermerk und `NOTICE`-Datei), die Patentlizenz ist ausdrücklich erteilt und Beiträge Dritter sind geregelt. Copyleft-Lizenzen (GPL, AGPL) DÜRFEN NICHT verwendet werden. |
| R-ENV-12 | Jede Datei von davServices MUSS einen Lizenzkopf tragen. `LICENSE`, `NOTICE`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md` und `SECURITY.md` MÜSSEN vorhanden sein. |

### 2.1 Wartbarkeit und Dokumentation

| ID | Anforderung |
|---|---|
| R-CODE-01 | Der Quellcode MUSS auf Lesbarkeit und Wartbarkeit hin gestaltet sein. Verständlichkeit hat Vorrang vor Kürze und vor Mikrooptimierung. |
| R-CODE-02 | Eine Methode SOLL höchstens 40 Zeilen umfassen, eine Klasse höchstens 400. Überschreitungen MÜSSEN begründet werden. Zyklomatische Komplexität je Methode höchstens 10, Ausnahmen (Parser, Filterauswertung) sind zu kennzeichnen. |
| R-CODE-03 | Verschachtelungstiefe höchstens 4. Frühe Rückgabe ist der verschachtelten Bedingung vorzuziehen. |
| R-CODE-04 | Jede öffentliche Klasse, jedes Interface und jede öffentliche Methode MUSS einen DocBlock mit **Fließtext** besitzen: eine Zeile Zweck, geworfene Exceptions, bei nicht offensichtlichem Verhalten ein kurzes Beispiel. Typangaben gehören in die **Signatur**; ein `@param`/`@return`, das die Signatur nur wiederholt, DARF NICHT geschrieben werden und wird von `no_superfluous_phpdoc_tags` entfernt. Ergänzende Typangaben im DocBlock sind dort verpflichtend, wo die Signatur sie nicht ausdrücken kann — etwa `@return list<string>` statt `array`. Ein DocBlock, der ausschließlich aus Annotationen besteht, erfüllt diese Anforderung NICHT. Ausgenommen ist `__construct`: er wird vom DocBlock seiner Klasse abgedeckt, und eine Beschreibung promoted properties würde gegen R-CODE-05 verstoßen. Erzwungen durch `bin/check-docblocks.php`. |
| R-CODE-05 | **Kommentare erklären das Warum, nicht das Was.** Ein Kommentar, der den Code wiederholt, DARF NICHT geschrieben werden. Wo eine RFC-Vorgabe oder ein Client-Verhalten den Code bestimmt, MUSS die Fundstelle genannt werden (`// RFC 4918 §10.4.2: ...`). |
| R-CODE-06 | Jedes Verzeichnis einer Schicht MUSS eine `README.md` von höchstens einer Seite besitzen: Zweck, wichtigste Klassen, Einstiegspunkt, Abgrenzung. |
| R-CODE-07 | Namen MÜSSEN aussagekräftig sein. Abkürzungen sind nur zulässig, wenn sie aus den RFCs stammen (`etag`, `uid`, `acl`, `ctag`). |
| R-CODE-08 | Tote Codepfade, auskommentierter Code und ungenutzte Importe DÜRFEN NICHT eingecheckt werden; ein Linter MUSS dies erzwingen. |
| R-CODE-09 | Öffentliche Schnittstellen von davServices MÜSSEN rückwärtskompatibel bleiben; Brüche nur mit Hauptversionssprung und `@deprecated`-Übergangsfrist von mindestens einer Nebenversion. |
| R-CODE-10 | **Zielwert der Testabdeckung für davServices ist 100 % Zeilen- und Zweigabdeckung.** Nicht erreichbare Zeilen MÜSSEN mit `@codeCoverageIgnore` und einer Begründung gekennzeichnet werden. Die CI MUSS bei Unterschreitung eines konfigurierten Schwellwerts fehlschlagen. |
| R-CODE-11 | **Begründung:** davServices ist eine Bibliothek ohne Benutzeroberfläche, deren Verhalten vollständig durch RFCs bestimmt ist. Jeder Zweig ist damit spezifiziert und prüfbar. |
| R-CODE-12 | davServices MUSS RFC 4918 vollständig und nachweisbar einhalten. Die RFC-Konformitätsmatrix (R-REL-03) MUSS je Abschnitt von RFC 4918, 3744, 4791 und 6352 den Umsetzungsstand und den zugehörigen Test benennen. |

---

---

## 3. Normative Referenzen

### 3.1 HTTP und WebDAV

| RFC | Inhalt | Verbindlichkeit |
|---|---|---|
| 9110 / 9111 / 9112 | HTTP-Semantik, Caching, HTTP/1.1 | MUSS |
| 4918 | WebDAV-Kern | MUSS |
| 3744 | WebDAV Access Control Protocol | MUSS |
| 5397 | Current Principal Extension | MUSS |
| 5689 | Extended MKCOL | MUSS |
| 6578 | Collection Synchronization | MUSS |
| 4331 | Quota and Size Properties | MUSS |
| 3253 | nur `REPORT`-Methodendefinition | MUSS |
| 4790 / 5051 | Kollationen | MUSS |
| 7240 / 8144 | `Prefer`, `return=minimal`, `depth-noroot` | SOLL |
| 6764 | Diensterkennung über `.well-known` und DNS | MUSS |
| 5789 | PATCH | KANN |

### 3.2 Kalender

| RFC | Inhalt | Verbindlichkeit |
|---|---|---|
| 4791 | CalDAV | MUSS |
| 5545 | iCalendar | MUSS |
| 5546 | iTIP | MUSS, sofern Scheduling aktiv |
| 6638 | CalDAV Scheduling | siehe Abschnitt 11.4 |
| 7986 | Neue iCalendar-Properties | SOLL |
| 6047 | iMIP | KANN |
| 7529 | `RSCALE` | KANN |
| 7953 | `VAVAILABILITY` | KANN |
| 7809 | Zeitzonen per Referenz | KANN |
| 8607 | Managed Attachments | KANN |
| 7265 | jCal | KANN |

### 3.3 Kontakte

| RFC | Inhalt | Verbindlichkeit |
|---|---|---|
| 6352 | CardDAV | MUSS |
| 6350 | vCard 4.0 | MUSS |
| 2426 | vCard 3.0 | MUSS |
| 2425 | MIME Directory Profile | MUSS |
| 6473 / 6474 / 6715 / 6869 | vCard-Erweiterungen | SOLL |
| 6351 / 7095 | xCard / jCard | KANN |

### 3.4 De-facto-Standards

| Quelle | Inhalt | Verbindlichkeit |
|---|---|---|
| Apple CalendarServer | `CS:getctag` | MUSS |
| Apple CalendarServer | `calendar-proxy-read` / `-write` | SOLL |
| Apple CalendarServer | `calendar-color`, `calendar-order`, `source` | SOLL |
| `draft-pot-caldav-sharing`, `draft-pot-webdav-notifications` | Freigaben und Benachrichtigungen | MUSS |
| WebDAV-Push (Entwurf) | Push an Clients | KANN |

---

---

## 5. Architektur

| ID | Anforderung |
|---|---|
| R-ARC-01 | Schichtung: (1) HTTP, (2) XML, (3) DAV-Kern, (4) Protokoll-Plugins, (5) Backend-Abstraktion, (6) Referenz-Backends. Zugriff ausschließlich auf tiefer liegende Schichten. |
| R-ARC-02 | Alle Protokollerweiterungen (ACL, CalDAV, CardDAV, Sync, Locking, Sharing, Scheduling) MÜSSEN als optional registrierbare Plugins realisiert sein. Reiner WebDAV-Betrieb MUSS möglich sein. |
| R-ARC-03 | Es MUSS ein typsicherer Event-Emitter existieren (Registrierung mit Priorität, Abbruch der Kette durch Ergebnisobjekt). Events MÜSSEN Objekte sein. |
| R-ARC-04 | Die Liste der Erweiterungspunkte ist Teil des öffentlichen Kompatibilitätsversprechens und MUSS mindestens umfassen: `beforeMethod`, `afterMethod`, `beforeBind`, `afterBind`, `beforeUnbind`, `afterUnbind`, `beforeWriteContent`, `afterWriteContent`, `beforeCreateFile`, `afterCreateFile`, `propFind`, `propPatch`, `beforeMove`, `afterMove`, `report`, `method:<VERB>`, `exception`, `schedule`. |
| R-ARC-05 | Abhängigkeiten MÜSSEN per Konstruktorinjektion übergeben werden. Statischer Zustand, Singletons und globale Variablen DÜRFEN NICHT verwendet werden. |
| R-ARC-06 | Öffentliche Erweiterungspunkte MÜSSEN als `interface` definiert sein. |
| R-ARC-07 | Die Bibliothek MUSS mehrfach im selben Prozess instanziierbar sein. |
| R-ARC-08 | Nicht öffentliche Klassen MÜSSEN als `@internal` markiert sein. |

### 5.1 Baum- und Knotenmodell

| ID | Anforderung |
|---|---|
| R-TREE-01 | Kernschnittstellen mindestens: `INode`, `ICollection`, `IFile`, `IExtendedCollection`, `IProperties`, `IMoveTarget`, `ICopyTarget`, `IQuota`, `IMultiGet`, `IFilter`. |
| R-TREE-02 | `IFile::get()` MUSS eine Stream-Ressource zurückgeben dürfen; die Auslieferung MUSS streamend erfolgen. |
| R-TREE-03 | `IFile::put()` MUSS Stream-Ressourcen entgegennehmen können. |
| R-TREE-04 | Pfadauflösung MUSS URL-dekodiert, normalisiert und gegen Directory Traversal abgesichert erfolgen (`..`, `%2e%2e`, Backslash, NUL-Byte, Unicode-Tricks). |
| R-TREE-05 | Knoten MÜSSEN innerhalb eines Requests zwischengespeichert werden. |
| R-TREE-06 | `MOVE` und `COPY` innerhalb eines Backends MÜSSEN an dieses delegierbar sein. |

---

---

## 6. HTTP-Schicht

| ID | Anforderung |
|---|---|
| R-HTTP-01 | Eigene Wertobjekte `Request` und `Response` mit klar definierter Veränderbarkeit. |
| R-HTTP-02 | SAPI-Adapter MUSS Requests aus `$_SERVER`, `php://input` und Header-Funktionen erzeugen und Responses streamend ausgeben. |
| R-HTTP-03 | Der Request-Body MUSS als Stream verfügbar und mehrfach lesbar sein (Pufferung auf `php://temp` mit konfigurierbarer Speichergrenze). |
| R-HTTP-04 | Header-Zugriff MUSS case-insensitiv sein; Mehrfachvorkommen MÜSSEN erhalten bleiben. |
| R-HTTP-05 | `Range`-Requests für `GET` MÜSSEN unterstützt werden (Byte- und Suffix-Ranges, `206`, `Content-Range`, `416`). Multipart-Ranges KÖNNEN entfallen. |
| R-HTTP-06 | Konditionale Requests MÜSSEN nach RFC 9110 §13.2.2 ausgewertet werden: `If-Match`, `If-None-Match`, `If-Modified-Since`, `If-Unmodified-Since`. |
| R-HTTP-07 | Der WebDAV-`If`-Header (RFC 4918 §10.4) MUSS vollständig geparst werden: getaggte und ungetaggte Listen, `Not`, State-Tokens, ETags, Verschachtelung. Eigenständige Unit-Tests mit mindestens 30 Fällen. |
| R-HTTP-08 | ETags MÜSSEN als starke ETags geliefert werden. Schwache ETags MÜSSEN mit `W/` gekennzeichnet und beim Vergleich entsprechend behandelt werden. |
| R-HTTP-09 | `Expect: 100-continue` MUSS korrekt behandelt werden. |
| R-HTTP-10 | `HEAD` MUSS identische Header wie `GET` liefern, ohne Body. |
| R-HTTP-11 | `OPTIONS` MUSS `DAV`-Compliance-Klassen dynamisch aus den aktiven Plugins ermitteln, dazu `Allow` und `MS-Author-Via: DAV`. |
| R-HTTP-12 | Konfigurierbare Obergrenzen für Request-Bodies, getrennt nach XML und Ressourcen-Upload. Überschreitung: `413`. |
| R-HTTP-13 | Bei streamenden Antworten MÜSSEN Ausgabepuffer korrekt geleert werden. |
| R-HTTP-14 | URI-Behandlung MUSS in einer eigenen, unit-getesteten Utility-Klasse gekapselt sein. |

---

---

## 7. XML-Schicht

| ID | Anforderung |
|---|---|
| R-XML-01 | Eigener Reader auf `XMLReader`, eigener Writer auf `XMLWriter`. `simplexml` DARF NICHT zum Parsen von Requests verwendet werden. |
| R-XML-02 | Registry, die `{namespace}localname` auf Deserialisierer und Serialisierer abbildet; erweiterbar durch Plugins. |
| R-XML-03 | Auswertung ausschließlich über Namespace-URIs; Präfixe sind beliebig. |
| R-XML-04 | Externe Entitäten, DTD-Verarbeitung und Netzwerkzugriffe MÜSSEN abgeschaltet sein. Entity-Expansion MUSS unterbunden sein. |
| R-XML-05 | Konfigurierbare Grenzen für Verschachtelungstiefe, Elementanzahl und Dokumentgröße. Überschreitung: `400`, niemals Speicherfehler. |
| R-XML-06 | Ungültiges XML MUSS zu definierter Exception mit passendem Status und `DAV:error`-Body führen. |
| R-XML-07 | Der Writer MUSS `207 Multi-Status` korrekt erzeugen, inklusive verschachtelter `DAV:response`, `DAV:propstat`, `DAV:error`, `DAV:responsedescription`. |
| R-XML-08 | Ausgabe MUSS UTF-8 sein; andere Eingabe-Encodings MÜSSEN konvertiert oder abgelehnt werden. |

---

---

## 8. WebDAV-Kern

### 8.1 Methoden

| ID | Anforderung |
|---|---|
| R-DAV-01 | Zu implementieren: `OPTIONS`, `GET`, `HEAD`, `PUT`, `DELETE`, `MKCOL`, `COPY`, `MOVE`, `PROPFIND`, `PROPPATCH`, `REPORT`, `LOCK`, `UNLOCK`, `POST`, `MKCALENDAR`. |
| R-DAV-02 | `PROPFIND` MUSS `Depth: 0` und `1` unterstützen. `Depth: infinity` SOLL standardmäßig mit `403` und `DAV:propfind-finite-depth` abgelehnt werden, MUSS aber aktivierbar sein. |
| R-DAV-03 | `PROPFIND` MUSS `allprop`, `propname`, `prop` und `allprop` + `include` unterstützen. |
| R-DAV-04 | Nicht verfügbare Properties: eigener `propstat`-Block mit `404`. Fehlende Leseberechtigung: `403`. |
| R-DAV-05 | `PROPPATCH` MUSS atomar sein; bei Fehlschlag MÜSSEN alle übrigen Properties `424` erhalten und nichts persistiert werden. |
| R-DAV-06 | `DELETE` auf Kollektionen MUSS rekursiv erfolgen; Teilfehler als `207`. |
| R-DAV-07 | `COPY`/`MOVE` MÜSSEN `Destination`, `Overwrite`, `Depth` auswerten und `201`, `204`, `403`, `409`, `412`, `423`, `502` sachgerecht setzen. |
| R-DAV-08 | `MKCOL` MUSS die erweiterte Form nach RFC 5689 unterstützen. |
| R-DAV-09 | `PUT` MUSS `Content-Range` mit `400` ablehnen, sofern kein Partial-Update-Plugin aktiv ist. |
| R-DAV-10 | Ein Verzeichnis-Browser-Plugin MUSS als Entwicklungshilfe existieren, standardmäßig deaktiviert und XSS-sicher sein. Es ist **nicht** die Weboberfläche und DARF im Produktivbetrieb von der Anwendung NICHT aktiviert werden. |

### 8.2 Properties

| ID | Anforderung |
|---|---|
| R-PROP-01 | Live-Properties mindestens: `DAV:resourcetype`, `getcontentlength`, `getcontenttype`, `getetag`, `getlastmodified`, `creationdate`, `displayname`, `supportedlock`, `lockdiscovery`, `supported-report-set`, `current-user-principal`, `principal-URL`, `current-user-privilege-set`, `quota-available-bytes`, `quota-used-bytes`. |
| R-PROP-02 | Dead Properties MÜSSEN über ein austauschbares Backend persistiert werden. |
| R-PROP-03 | Property-Werte MÜSSEN beliebiges XML verlustfrei aufnehmen. |
| R-PROP-04 | Bei `MOVE` und `DELETE` MÜSSEN Dead Properties mitgeführt bzw. entfernt werden. |
| R-PROP-05 | Ein `PropFind`-Ergebnisobjekt MUSS Beiträge aus Knoten, Plugins und Property-Storage in definierter Reihenfolge zusammenführen. |

### 8.3 Locking

| ID | Anforderung |
|---|---|
| R-LOCK-01 | `LOCK`/`UNLOCK` nach RFC 4918: Write-Locks, exklusiv und geteilt, `Depth: 0` und `infinity`. |
| R-LOCK-02 | Lock-Tokens als `opaquelocktoken:` mit kryptographisch zufälliger UUID. |
| R-LOCK-03 | `Timeout`-Header MUSS unterstützt werden (`Second-<n>`, `Infinite`) mit konfigurierbarem Maximum; abgelaufene Locks MÜSSEN entfernt werden. |
| R-LOCK-04 | Lock-Prüfung in allen schreibenden Methoden; `423` bzw. `412` bei Verstoß. |
| R-LOCK-05 | Lock-Backend austauschbar; Referenzimplementierungen Dateisystem und PDO. |
| R-LOCK-06 | Ohne Lock-Plugin MUSS der Server weiterlaufen (`DAV: 1` statt `1,2`). |

---

---

## 9. Zugriffskontrolle

| ID | Anforderung |
|---|---|
| R-ACL-01 | RFC 3744 mindestens: `acl`, `owner`, `group`, `supported-privilege-set`, `current-user-privilege-set`, `acl-restrictions`, `inherited-acl-set`, `principal-collection-set`. |
| R-ACL-02 | Privilegienhierarchie MUSS korrekt aggregieren: `all` → `read`, `write` → `write-content`, `write-properties`, `bind`, `unbind`, `read-acl`, `write-acl`, `read-current-user-privilege-set`, `CALDAV:read-free-busy`. |
| R-ACL-03 | REPORTs `principal-property-search`, `principal-search-property-set`, `expand-property` MÜSSEN implementiert sein. |
| R-ACL-04 | Gruppenmitgliedschaften über `group-member-set` und `group-membership`, transitiv aufgelöst mit Zyklenschutz. |
| R-ACL-05 | Die Prüfung MUSS zentral im Kern vor jeder Operation erfolgen (Fail-Closed). |
| R-ACL-06 | Bei fehlender Leseberechtigung SOLL konfigurierbar `403` oder `404` zurückgegeben werden. |
| R-ACL-07 | Proxy-Principals (`calendar-proxy-read`/`-write`) SOLLEN unterstützt werden. |
| R-ACL-08 | `PROPFIND Depth: 1` auf Principal-Kollektionen MUSS begrenzbar sein; Überschreitung führt zu `507` innerhalb des Multi-Status. |

---

---

## 10. Synchronisation

| ID | Anforderung |
|---|---|
| R-SYNC-01 | `DAV:sync-collection` nach RFC 6578 inklusive `sync-token`, `sync-level` (`1`, `infinite`), `limit`/`nresults`. |
| R-SYNC-02 | Sync-Tokens MÜSSEN monoton steigend, undurchsichtig und je Kollektion eindeutig sein. |
| R-SYNC-03 | Ungültiges oder zu altes Token: `403` mit `DAV:valid-sync-token`. |
| R-SYNC-04 | Bei Überschreitung des konfigurierbaren Limits MUSS die Kollektion mit `507` abgeschnitten und ein Zwischentoken geliefert werden. |
| R-SYNC-05 | Löschungen MÜSSEN als `404` in der Sync-Antwort erscheinen; das Journal MUSS über einen konfigurierbaren Zeitraum vorgehalten und danach aufgeräumt werden. |
| R-SYNC-06 | `{http://calendarserver.org/ns/}getctag` MUSS zusätzlich bereitgestellt werden. |

---

---

## 11. CalDAV

### 11.1 Struktur und Properties

| ID | Anforderung |
|---|---|
| R-CAL-01 | `MKCALENDAR` inklusive initialem `DAV:set` und der Preconditions `calendar-collection-location-ok` und `valid-calendar-data`. |
| R-CAL-02 | Properties: `calendar-home-set`, `calendar-user-address-set`, `calendar-description`, `calendar-timezone`, `supported-calendar-component-set`, `supported-calendar-data`, `max-resource-size`, `min-date-time`, `max-date-time`, `max-instances`, `max-attendees-per-instance`, `supported-collation-set`. |
| R-CAL-03 | `CS:calendar-color`, `CS:calendar-order`, `apple:calendar-order` SOLLEN unterstützt werden. |
| R-CAL-04 | Kalenderabonnements (`CS:subscribed` mit `CS:source`) SOLLEN als eigener Kollektionstyp unterstützt werden. |
| R-CAL-05 | Eindeutigkeit der `UID` innerhalb einer Kalender-Kollektion MUSS erzwungen werden (`no-uid-conflict`). |
| R-CAL-06 | Kalender-Kollektionen DÜRFEN NICHT verschachtelt werden. |
| R-CAL-07 | Eine Ressource MUSS genau eine `UID` und genau einen Komponententyp enthalten (`valid-calendar-object-resource`). |

**Verbindliche Obergrenzen** (schließt die zuvor offene Festlegung):

| Property | Wert |
|---|---|
| `CALDAV:max-resource-size` | 10.485.760 (10 MB) |
| `CARDDAV:max-resource-size` | 8.388.608 (8 MB) |
| `CALDAV:min-date-time` | `19000101T000000Z` |
| `CALDAV:max-date-time` | `20991231T235959Z` |
| `CALDAV:max-instances` | 5.000 |
| `CALDAV:max-attendees-per-instance` | 250 |
| Harte Iterationsgrenze der RRULE-Expansion | 10.000 |
| Höchstzahl `href` je Multiget | 5.000 |

| ID | Anforderung |
|---|---|
| R-CAL-08 | Die Werte MÜSSEN konfigurierbar sein, die genannten sind Vorgaben. Sie MÜSSEN über die entsprechenden Properties gemeldet werden. |
| R-CAL-09 | Wird eine Grenze überschritten, MUSS mit der zugehörigen Precondition abgelehnt werden, niemals mit Abbruch. |

### 11.2 REPORTs

| ID | Anforderung |
|---|---|
| R-CAL-10 | `calendar-query` vollständig: `comp-filter`, `prop-filter`, `param-filter`, `time-range`, `text-match` (mit `negate-condition` und `collation`), `is-not-defined`, beliebig verschachtelt. |
| R-CAL-11 | `calendar-multiget` MUSS die angeforderten Ressourcen in einer Backend-Abfrage laden können. |
| R-CAL-12 | `free-busy-query` MUSS korrekte `VFREEBUSY`-Antworten liefern, inklusive `TRANSP`, `STATUS` und Wiederholungen. |
| R-CAL-13 | `calendar-data` MUSS `comp`/`prop`-Beschränkung, `expand`, `limit-recurrence-set`, `limit-freebusy-set` unterstützen. |
| R-CAL-14 | Bei `expand` MÜSSEN Wiederholungen in Einzelinstanzen aufgelöst werden, mit `RECURRENCE-ID`-Overrides und Umrechnung nach UTC. |
| R-CAL-15 | `time-range` MUSS für `VEVENT`, `VTODO`, `VJOURNAL`, `VFREEBUSY` und `VALARM` nach RFC 4791 §9.9 ausgewertet werden, einschließlich fehlendem `DTEND`, `DURATION`, `DUE` und ganztägiger Termine. |
| R-CAL-16 | `time-range` MUSS im Backend vorgefiltert werden. Vollständige In-Memory-Expansion DARF NICHT der Regelfall sein. |

### 11.3 Freigabe-Protokoll

| ID | Anforderung |
|---|---|
| R-CAL-20 | Das CalDAV-Sharing-Protokoll (`POST` mit `CS:share`, `CS:invite`, `CS:shared-url`, `CS:allowed-sharing-modes`) MUSS implementiert sein, damit Apple-Clients die native Freigabefunktion nutzen können. |
| R-CAL-21 | Eine Notification-Kollektion (`CS:notification-URL`) MUSS bereitgestellt werden. |

### 11.4 Scheduling

| ID | Anforderung |
|---|---|
| R-CAL-30 | **Scheduling nach RFC 6638 ist Bestandteil von v1.0** und MUSS als separat abschaltbares Plugin realisiert werden. |
| R-CAL-31 | Properties: `schedule-inbox-URL`, `schedule-outbox-URL`, `schedule-default-calendar-URL`, `schedule-calendar-transp`, `schedule-tag`. |
| R-CAL-32 | Implicit Scheduling MUSS bei `PUT`/`DELETE` von Objekten mit `ORGANIZER`/`ATTENDEE` iTIP-Nachrichten erzeugen (`REQUEST`, `REPLY`, `CANCEL`, `ADD`). |
| R-CAL-33 | Zustellung über austauschbares Transport-Interface. **Verbindlich in v1.0: ausschließlich lokale Zustellung** in die Inbox des Empfänger-Principals. |
| R-CAL-36 | **iMIP — Einladungen an und von externen Adressen — ist für v1.1 vorgesehen**, in beide Richtungen. Das Transport-Interface MUSS bereits in v1.0 so geschnitten sein, dass ein iMIP-Transport ohne Änderung des Scheduling-Kerns ergänzbar ist. |
| R-CAL-37 | Externe Teilnehmer (`ATTENDEE` mit `mailto:`-Adresse ohne zugehörigen Principal) MÜSSEN bereits in v1.0 im Objekt erhalten bleiben und mit `schedule-status` `3.7` („Empfänger unbekannt") gemeldet werden, statt die Anfrage abzulehnen. Damit funktionieren Termine mit externen Gästen, nur ohne automatische Zustellung. |
| R-CAL-34 | `POST` auf die Outbox MUSS Free-Busy-Anfragen beantworten. |
| R-CAL-35 | Schleifenschutz über Erkennung wiederholter `SEQUENCE`/`DTSTAMP`-Kombinationen. |

---

---

## 12. CardDAV

| ID | Anforderung |
|---|---|
| R-CARD-01 | Adressbücher über erweitertes `MKCOL` mit `CARDDAV:addressbook` als `resourcetype`. |
| R-CARD-02 | Properties: `addressbook-home-set`, `principal-address`, `addressbook-description`, `supported-address-data`, `max-resource-size`, `supported-collation-set`. |
| R-CARD-03 | `addressbook-query`: `prop-filter`, `param-filter`, `text-match`, `is-not-defined`, `allof`/`anyof`, `limit`/`nresults`. |
| R-CARD-04 | `addressbook-multiget` MUSS implementiert sein. |
| R-CARD-05 | `address-data` MUSS Property-Beschränkungen und Versionsangabe unterstützen. |
| R-CARD-06 | Konvertierung zwischen vCard 3.0 und 4.0 MUSS verlustarm möglich sein (Speicherform siehe Abschnitt 22). |
| R-CARD-07 | Eindeutigkeit der `UID` innerhalb eines Adressbuchs (`no-uid-conflict`). |
| R-CARD-08 | Eingebettete Binärdaten MÜSSEN in beiden Kodierungen verarbeitet werden (Base64 bei 3.0, Data-URI bei 4.0). |
| R-CARD-09 | `supported-collation-set` MUSS `i;unicode-casemap`, `i;ascii-casemap` und `i;octet` melden. |

---

---

## 13. iCalendar- und vCard-Verarbeitung

### 13.1 Parser und Serializer

| ID | Anforderung |
|---|---|
| R-VOBJ-01 | Parser für iCalendar (RFC 5545) und vCard (2.1, 3.0, 4.0) mit korrektem Line-Folding, Escaping, Parameterbehandlung und Zeilenendevarianten. |
| R-VOBJ-02 | Streamende Verarbeitung; Dateien über 10 MB DÜRFEN NICHT proportional Speicher belegen. |
| R-VOBJ-03 | Zwei Betriebsarten: **strikt** (Vorgabe, lehnt fehlerhafte Eingaben ab) und **nachsichtig** (repariert häufige Client-Fehler). Die Wahl der Betriebsart hat unmittelbare Protokollfolgen, siehe Abschnitt 22. |
| R-VOBJ-04 | Validierungsfunktion mit Schweregraden `REPAIR`, `WARNING`, `ERROR` sowie eine Reparaturfunktion. |
| R-VOBJ-05 | Serialisierung MUSS bei 75 Oktetten falten, ohne UTF-8-Mehrbyte-Sequenzen zu zerschneiden. |
| R-VOBJ-06 | Typisierte Property-Klassen: `DateTime`, `Date`, `Duration`, `Period`, `Recur`, `Text`, `Integer`, `Float`, `Boolean`, `UtcOffset`, `Uri`, `CalAddress`, `Binary`, `LanguageTag`. |
| R-VOBJ-07 | Ergonomischer Zugriff über `ArrayAccess` und `Iterator` ohne Verlust von Typsicherheit an öffentlichen APIs. |
| R-VOBJ-08 | Konvertierung zwischen vCard 2.1/3.0/4.0; jCal und jCard optional. |

### 13.2 Wiederholungsregeln

| ID | Anforderung |
|---|---|
| R-RRULE-01 | Vollständige `RRULE`-Expansion nach RFC 5545 §3.3.10: `FREQ`, `INTERVAL`, `COUNT`, `UNTIL`, `BYSECOND`, `BYMINUTE`, `BYHOUR`, `BYDAY` (mit Ordinalpräfix), `BYMONTHDAY`, `BYYEARDAY`, `BYWEEKNO`, `BYMONTH`, `BYSETPOS`, `WKST`. |
| R-RRULE-02 | `EXDATE`, `RDATE` (Datum, Datum-Zeit, Period) und `EXRULE` (Legacy) MÜSSEN berücksichtigt werden. |
| R-RRULE-03 | Overrides via `RECURRENCE-ID` inklusive `RANGE=THISANDFUTURE`. |
| R-RRULE-04 | Expansion als Iterator (Lazy Evaluation) mit harter Iterationsgrenze nach R-CAL-07. |
| R-RRULE-05 | Grenzfälle: 29. Februar bei jährlicher Wiederholung, 31. eines Monats bei monatlicher Wiederholung, `UNTIL` mit und ohne Zeitzone, `COUNT` mit `EXDATE`, Wiederholungen über Zeitumstellungen. |
| R-RRULE-06 | Validierung gegen einen öffentlichen Testvektorsatz; Abweichungen sind zu dokumentieren und zu begründen. |

### 13.3 Zeitzonen

| ID | Anforderung |
|---|---|
| R-TZ-01 | `VTIMEZONE` MUSS auf `DateTimeZone` abgebildet werden über `TZID`, `X-LIC-LOCATION`, `X-MICROSOFT-CDO-TZID` und einen gepflegten Alias-Katalog (Microsoft, Lotus, Outlook). |
| R-TZ-02 | Ohne Zuordnung MUSS die `VTIMEZONE` selbst ausgewertet werden. Ein stiller Rückfall auf UTC DARF NICHT ohne Protokolleintrag erfolgen. |
| R-TZ-03 | Floating-Zeiten MÜSSEN als solche erhalten bleiben und erst bei Bedarf gegen `CALDAV:calendar-timezone` aufgelöst werden. |
| R-TZ-04 | Ganztägige Termine (`VALUE=DATE`) DÜRFEN NICHT implizit in UTC-Zeitpunkte gewandelt werden. |
| R-TZ-05 | Es MUSS eine Funktion geben, die für einen Zeitraum eine minimale, gültige `VTIMEZONE` erzeugt. |

---

---

## 14. Backend-Schnittstellen

| ID | Anforderung |
|---|---|
| R-BE-01 | Interfaces mindestens: `IPrincipalBackend`, `ICalendarBackend`, `ICardBackend`, `IAuthBackend`, `ILockBackend`, `IPropertyStorageBackend`, `ISharingBackend`, `ISchedulingBackend`, `ISubscriptionBackend`, `INotificationBackend`, `IPrivilegeResolver`. |
| R-BE-02 | Die Schnitte MÜSSEN N+1-Abfragen vermeidbar machen: Batch-Methoden für Multiget und für gemeinsame Metadaten bei `PROPFIND Depth: 1`. |
| R-BE-03 | Verträge (Vor- und Nachbedingungen, zu werfende Exceptions) MÜSSEN vollständig dokumentiert sein. |
| R-BE-04 | davServices MUSS eine wiederverwendbare **Vertragstestsuite** mitliefern, gegen die jede Backend-Implementierung ihre Konformität nachweist. |
| R-BE-05 | Referenz-Backends (Dateisystem, einfaches PDO) verbleiben in davServices und DÜRFEN NICHT produktiv verwendet werden. |

### 14.1 Vertrag `IPrivilegeResolver`

Dies ist die einzige Naht, über die Anwendungswissen in die Protokollverarbeitung einfließt. Sie MUSS exakt definiert sein.

```php
namespace DavServices\Acl;

interface IPrivilegeResolver
{
    /**
     * Effektive Privilegien eines Principals auf genau einem Knoten.
     *
     * @param string|null $principalUri  Principal-URI oder null für nicht authentifiziert
     * @param string      $path          Normalisierter, entkodierter Pfad ohne führenden Slash
     * @return PrivilegeSet              Niemals null; leer bedeutet "kein Zugriff"
     * @throws NotFound                  Nur, wenn der Pfad nachweislich nicht existiert
     */
    public function forPath(?string $principalUri, string $path): PrivilegeSet;

    /**
     * Effektive Privilegien für mehrere Knoten in EINEM Aufruf.
     * Verpflichtender Bestandteil: verhindert N+1 bei PROPFIND Depth:1 und Multiget.
     *
     * @param string[] $paths
     * @return array<string, PrivilegeSet>  Schlüssel ist der Pfad; jeder Pfad MUSS
     *                                      im Ergebnis vorkommen, auch bei leerem Set.
     */
    public function forPaths(?string $principalUri, array $paths): array;

    /**
     * Principals, die auf diesem Knoten mindestens ein Privileg besitzen.
     * Wird für DAV:acl und die Freigabeübersicht benötigt.
     *
     * @return array<string, PrivilegeSet>  Schlüssel ist die Principal-URI
     */
    public function principalsForPath(string $path): array;
}
```

| ID | Anforderung |
|---|---|
| R-PRIV-01 | `forPaths()` ist verpflichtender Bestandteil des Interfaces. Eine Implementierung, die intern `forPath()` in einer Schleife aufruft, ist zulässig, erfüllt aber nicht den Zweck und MUSS in der Vertragstestsuite als Leistungsverstoß auffallen. |
| R-PRIV-02 | Die Auflösung MUSS innerhalb eines Requests memoisiert und seiteneffektfrei sein. |
| R-PRIV-03 | Bei `$principalUri === null` (nicht authentifiziert) MUSS ein leeres `PrivilegeSet` zurückgegeben werden, sofern nicht eine Veröffentlichung nach Abschnitt 18.3 greift. |
| R-PRIV-04 | Nicht existierende Pfade DÜRFEN NICHT zu einer Ausnahme führen, wenn sie Teil eines Batch-Aufrufs sind; sie erhalten ein leeres Set. |
| R-PRIV-05 | Der Resolver DARF NICHT die Existenz eines Knotens prüfen müssen; er beantwortet ausschließlich Rechtefragen. |
| R-PRIV-06 | `PrivilegeSet` MUSS aggregierte Privilegien liefern (`DAV:write` impliziert `write-content`, `write-properties`, `bind`, `unbind`). |

---

## Qualitätssicherung

| ID | Anforderung |
|---|---|
| R-QS-03 | Die Vertragstestsuite (R-BE-04) MUSS gegen alle Konfigurationen laufen. |
| R-QS-04 | davServices MUSS die Litmus-Testsuite in den Klassen `basic`, `copymove`, `props` und `locks` fehlerfrei bestehen; Abweichungen sind zu begründen. |
| R-QS-05 | davServices MUSS gegen CalDAVTester geprüft werden; bestandene und nicht bestandene Gruppen MÜSSEN als Kompatibilitätsmatrix dokumentiert werden. |
| R-QS-06 | Interoperabilität MUSS mit mindestens folgenden Clients verifiziert werden: iOS, macOS, Thunderbird, DAVx⁵, GNOME Evolution, eM Client, Outlook CalDAV Synchronizer. Ergebnis als Matrix. |
| R-QS-07 | Die Interop-Matrix MUSS Szenarien für lesenden und schreibenden Zugriff auf Kalender und Adressbücher enthalten. |
| R-QS-08 | Statische Analyse auf höchster praktikabler Stufe (PHPStan Level 8 oder Psalm Level 2). |
| R-QS-09 | Einheitlicher Coding-Standard, maschinell durchgesetzt. |
| R-QS-24 | **Mutationstests sind für davServices verpflichtend**: Mutation Score Indicator mindestens 85 %, auf abgedecktem Code mindestens 90 %. |
| R-QS-25 | **Begründung:** 100 % Zeilenabdeckung ist ohne eine einzige Zusicherung erreichbar — es genügt, jede Zeile auszuführen. Bei Protokollcode mit vielen ähnlichen Rückgabewerten ist das ein reales Risiko. Erst der Mutationstest, der den Code gezielt verändert und einen fehlschlagenden Test erwartet, macht die Abdeckungszahl aussagekräftig. |
| R-QS-26 | Der Mutationslauf ist zu langsam für jeden Commit und läuft nächtlich sowie vor jedem Release. |
| R-QS-27 | Die CI MUSS zusätzlich nachweisen, dass die Bibliothek **ohne die optionalen Extensions** (`intl`, `openssl`) und **ohne Composer** funktioniert. Beides sind Zusagen an Anwender, die sonst erst von diesen bemerkt würden. |
| R-QS-19 | Fuzzing für den iCalendar- und vCard-Parser sowie für die Bildverarbeitung. |
| R-QS-23 | Die vollständige Testsuite von davServices MUSS ohne weitere Anwendungen durchlaufen. Ein mitgeliefertes Beispielprojekt MUSS automatisiert gegen Litmus getestet werden. |

| ID | Anforderung |
|---|---|
| R-QS-30 | Automatisierte Prüfungen, die den Build brechen: null Laufzeitabhängigkeiten, Namensraumkonsistenz, Begriffssperre, Schichtenrichtung, Abdeckungsuntergrenze, Ladbarkeit ohne Composer, Lauffähigkeit ohne `intl`. |
| R-QS-31 | Für jede Prüfung MUSS ein Gegentest existieren, der einen absichtlichen Verstoß einbaut und nachweist, dass die Prüfung anschlägt. Eine Prüfung, die nie fehlschlägt, ist wertlos. |

---

## 34. Auslieferung und Phasenplan

| ID | Anforderung |
|---|---|
| R-REL-01 | davServices MUSS nach Semantic Versioning versioniert werden und eine eigene `CHANGELOG`-Datei führen. Änderungen an der öffentlichen Schnittstelle MÜSSEN gesondert ausgewiesen werden. |
| R-REL-02 | Eine Kompatibilitätsmatrix für davServices, PHP-Versionen und die mitgelieferten Referenz-Backends MUSS veröffentlicht werden. |
| R-REL-04 | **davServices wird über Packagist veröffentlicht** (`davservices/davservices`). Die Veröffentlichung erfolgt durch Setzen eines Git-Tags; Packagist zieht den Stand über den GitHub-Webhook selbst. Ein Hochladen von Artefakten entfällt, da PHP-Bibliotheken als Quelltext ausgeliefert werden. |
| R-REL-05 | Eine `composer.json` MUSS vorhanden sein mit: Paketname, `type: library`, `license: Apache-2.0`, PSR-4-Autoload auf `DavServices\` → `src/davservices/`, und unter `require` ausschließlich `php` und `ext-*`-Einträgen. Ein Fremdpaket unter `require` MUSS den Build brechen (R-QS-24). |
| R-REL-08 | Bei jedem Versions-Tag MUSS die Pipeline sämtliche Prüfungen erneut ausführen, das Prüfsummen-Manifest erzeugen und ein GitHub-Release mit Quellarchiv, Manifest und SHA-256-Summe anlegen. Ein Tag DARF NICHT etwas veröffentlichen, das die Pipeline zurückgewiesen hätte. |
| R-REL-03 | Mitzuliefern: API-Dokumentation aller öffentlichen Klassen, Einstiegshandbuch mit lauffähigen Minimalbeispielen, Backend-Handbuch mit Vertragsbeschreibung, Serverkonfigurationsvorlagen, RFC-Konformitätsmatrix, Betriebshandbuch, Migrationsleitfaden von `sabre/dav`. |

| Phase | Inhalt |
|---|---|
| P1 | Fundament: HTTP, XML, Events, Baummodell, Exceptions, Autoloader |
| P2 | WebDAV-Kern und Dateisystem-Backend |
| P3 | Locking, `If`-Header, ACL, Principals, Auth-Plugin |
| P4 | iCalendar/vCard-Bibliothek: Parser, Serializer, RRULE, Zeitzonen |
| P5 | CardDAV |
| P6 | CalDAV |
| P7 | Synchronisation, Sharing-Protokoll, Notifications |
| P8 | Scheduling |
| P9 | Härtung, Beispielprojekt, Vertragstestsuite, **Freigabe davServices v1.0** |

| ID | Anforderung |
|---|---|
| R-PLAN-01 | davServices MUSS mit P9 einen eigenständig freigabefähigen Stand erreichen. |
| R-PLAN-03 | Ab P3 MUSS ein durchgängiger Interop-Test mit mindestens einem realen DAV-Client Bestandteil jeder Phasenabnahme sein. |

---

---

## Offene Punkte

| ID | Frage | Stand |
|---|---|---|
| S-01 | Vendor-Name bei Packagist sichern (`davservices` auf https://packagist.org) | **Handlung** — vor dem ersten Tag |
| S-02 | Zeitpunkt der ersten Veröffentlichung | **geschlossen** — `v0.1.0` sobald die CI grün ist, um Name, Webhook und Release-Ablauf zu erproben, bevor sie unter Zeitdruck gebraucht werden |

**Es sind keine fachlichen Punkte offen.** Die Anforderungen sind durch RFCs bestimmt und enthalten keine offenen fachlichen Fragen.
