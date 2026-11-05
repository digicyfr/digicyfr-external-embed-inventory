#!/usr/bin/env python3
"""Create the bounded singular Polish catalog from runtime msgids."""
from pathlib import Path
import json
import re

root = Path(__file__).resolve().parents[1]
translations = {
    "A private, local inventory of external references found in saved post and page content.": "Prywatny, lokalny wykaz zewnętrznych odwołań zapisanych we wpisach i stronach.",
    "External Embed Inventory": "Wykaz zewnętrznych osadzeń",
    "External Embed Inventory requires InnoDB for safe resumable scans.": "Wykaz zewnętrznych osadzeń wymaga InnoDB do bezpiecznego wznawiania skanów.",
    "External Embed Inventory could not initialize its scan lock.": "Nie udało się zainicjować blokady skanu.",
    "You cannot perform this action.": "Nie możesz wykonać tej czynności.",
    "You cannot view this report.": "Nie możesz wyświetlić tego raportu.",
    "Unknown action.": "Nieznana czynność.",
    "Local saved-content references only. A found host is not proof of a browser request or a privacy verdict. Rescan after content changes.": "Tylko odwołania z zapisanej treści. Znaleziony host nie dowodzi żądania przeglądarki ani zgodności z prywatnością. Po zmianie treści wykonaj nowy skan.",
    "Storage error. The previous complete report remains unchanged.": "Błąd zapisu danych. Poprzedni pełny raport pozostaje bez zmian.",
    "Could not lock the active report.": "Nie udało się zablokować aktywnego raportu.",
    "Scan action completed.": "Czynność skanu została wykonana.",
    "Scan action failed.": "Czynność skanu nie powiodła się.",
    "Scan": "Skan",
    "Current attempt:": "Bieżąca próba:",
    "Cursor:": "Kursor:",
    "Scanned:": "Przeskanowano:",
    "Skipped:": "Pominięto:",
    "Started (UTC):": "Rozpoczęto (UTC):",
    "Coverage reason:": "Przyczyna niepełnego zakresu:",
    "Candidates:": "Kandydaci:",
    "Unresolved relative:": "Nierozstrzygnięte względne:",
    "Unsupported attributes:": "Nieobsługiwane atrybuty:",
    "Invalid URLs:": "Nieprawidłowe adresy URL:",
    "Ignored values:": "Pominięte wartości:",
    "Unclassified or ambiguous:": "Niesklasyfikowane lub niejednoznaczne:",
    "Finished (UTC):": "Zakończono (UTC):",
    "Start a new scan": "Rozpocznij nowy skan",
    "Start scan": "Rozpocznij skan",
    "Continue (up to 10 posts)": "Kontynuuj (do 10 wpisów)",
    "Cancel attempt": "Anuluj próbę",
    "No complete report yet. CSV is unavailable until a scan completes without skips.": "Nie ma jeszcze pełnego raportu. CSV będzie dostępny po zakończeniu skanu bez pominięć.",
    "Active complete report": "Aktywny pełny raport",
    "Covered posts:": "Objęte wpisy:",
    "Download CSV": "Pobierz CSV",
    "View coverage and stale posts": "Zobacz zakres i nieaktualne wpisy",
    "In progress": "W toku",
    "Complete": "Pełny",
    "Incomplete": "Niepełny",
    "Cancelled": "Anulowany",
    "Unknown": "Nieznany",
    "Saved content exceeded 512 KiB.": "Zapisana treść przekroczyła 512 KiB.",
    "More than 300 URL candidates in a post.": "We wpisie znaleziono ponad 300 kandydatów URL.",
    "Block nesting limit exceeded.": "Przekroczono limit zagnieżdżenia bloków.",
    "Block count limit exceeded.": "Przekroczono limit liczby bloków.",
    "HTML tag count limit exceeded.": "Przekroczono limit liczby znaczników HTML.",
    "Saved HTML ended in an incomplete tag.": "Zapisany HTML kończy się niepełnym znacznikiem.",
    "Saved content parsing failed.": "Nie udało się przeanalizować zapisanej treści.",
    "Storage or coverage failed; retry Continue if it is available.": "Błąd zapisu lub zakresu; ponów Kontynuuj, jeśli jest dostępne.",
    "Exact host": "Dokładny host",
    "Source kind": "Rodzaj źródła",
    "All kinds": "Wszystkie rodzaje",
    "Evidence": "Typ wskazówki",
    "All evidence": "Wszystkie typy",
    "Saved resource URL": "Zapisany adres zasobu",
    "Hints and lazy URLs": "Wskazówki i adresy leniwe",
    "Filter": "Filtruj",
    "Host report query failed.": "Zapytanie raportu hostów nie powiodło się.",
    "Hosts found in saved content": "Hosty znalezione w zapisanej treści",
    "No supported saved external references found in this report. Unsupported and dynamic sources remain outside scope.": "W tym raporcie nie znaleziono obsługiwanych zapisanych odwołań zewnętrznych. Nieobsługiwane i dynamiczne źródła pozostają poza zakresem.",
    "Host": "Host",
    "Scheme": "Schemat",
    "Pages": "Strony",
    "Occurrences": "Wystąpienia",
    "Next page": "Następna strona",
    "Source report query failed.": "Zapytanie raportu źródeł nie powiodło się.",
    "Saved source references": "Zapisane odwołania źródłowe",
    "No matching saved references.": "Brak pasujących zapisanych odwołań.",
    "Page": "Strona",
    "Source": "Źródło",
    "Count": "Liczba",
    "Stale": "Nieaktualne",
    "Yes": "Tak",
    "No": "Nie",
    "Coverage query failed.": "Zapytanie o zakres nie powiodło się.",
    "Coverage, including pages without matches": "Zakres, także strony bez trafień",
    "Page ID": "ID strony",
    "Status": "Stan",
    "Stale or unavailable": "Nieaktualna lub niedostępna",
    "Current saved content": "Aktualna zapisana treść",
    "No complete report is available for export.": "Nie ma pełnego raportu do eksportu.",
    "Could not prepare a private CSV file.": "Nie udało się przygotować prywatnego pliku CSV.",
    "Could not lock the active report for export.": "Nie udało się zablokować aktywnego raportu do eksportu.",
    "CSV could not be completed. No file was downloaded.": "Nie udało się ukończyć CSV. Nie pobrano pliku.",
}

source = "\n".join(path.read_text() for path in [root / "digicyfr-external-embed-inventory.php", *sorted((root / "includes").glob("*.php"))])
ids = set(re.findall(r"(?:__|esc_html__)\(\s*'([^']+)'\s*,", source))
ids.add("A private, local inventory of external references found in saved post and page content.")
missing = ids - translations.keys()
if missing:
    raise SystemExit(f"Missing translations: {sorted(missing)}")
header = "Project-Id-Version: Digicyfr External Embed Inventory 0.1.0\nLanguage: pl_PL\nContent-Type: text/plain; charset=UTF-8\n"
items = [f"msgid {json.dumps('', ensure_ascii=False)}\nmsgstr {json.dumps(header, ensure_ascii=False)}"]
for msgid in sorted(ids):
    items.append(f"msgid {json.dumps(msgid, ensure_ascii=False)}\nmsgstr {json.dumps(translations[msgid], ensure_ascii=False)}")
(root / "languages/digicyfr-external-embed-inventory-pl_PL.po").write_text("\n\n".join(items) + "\n")
