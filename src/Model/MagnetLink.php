<?php

namespace TryHackX\HomepageBlocks\Model;

use Flarum\Database\AbstractModel;

/**
 * Cienki model na tabelę `magnet_links` z tryhackx/flarum-magnet-link.
 *
 * Używany wyłącznie do odczytu zagregowanych statystyk (liczba magnetów i suma
 * kliknięć) na endpointcie statystyk. Trzymamy własny lekki model zamiast sięgać
 * po klasę z magnet-linka, bo ta paczka jest tylko „suggest" — może jej nie być.
 * Gdy tabela nie istnieje, zapytania rzucą wyjątek przechwytywany w kontrolerze.
 *
 * Kontrakt nazw (tabela `magnet_links`, kolumna `click_count`) jest stałym,
 * cichym sprzężeniem z magnet-linkiem — patrz pamięć „magnet-link-hardening".
 */
class MagnetLink extends AbstractModel
{
    protected $table = 'magnet_links';
}
