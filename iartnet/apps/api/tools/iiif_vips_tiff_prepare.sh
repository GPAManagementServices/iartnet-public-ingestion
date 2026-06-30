#!/usr/bin/env bash
set -euo pipefail

# Convert JPG/JPEG/PNG/TIF/TIFF sources into IIIF/Cantaloupe-friendly tiled TIFFs.
# Usage: ./iiif_vips_tiff_prepare.sh INPUT_IMAGE OUTPUT_TIFF

usage() {
  cat <<USAGE
Usage:
  $0 INPUT_IMAGE OUTPUT_TIFF

Supported input extensions:
  .jpg .jpeg .png .tif .tiff
USAGE
}

die() {
  echo "ERROR: $*" >&2
  exit 1
}

log() {
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "Comando non trovato: $1"
}

lower() {
  printf '%s' "$1" | tr '[:upper:]' '[:lower:]'
}

make_temp_v() {
  if command -v mktemp >/dev/null 2>&1; then
    if mktemp --help 2>/dev/null | grep -q suffix; then
      mktemp --suffix=.v
      return
    fi
    mktemp "${TMPDIR:-/tmp}/iartnet_vips_XXXXXX.v"
    return
  fi
  die "mktemp non disponibile"
}

cleanup_files=()
cleanup() {
  for f in "${cleanup_files[@]:-}"; do
    [ -n "$f" ] && [ -e "$f" ] && rm -f "$f"
  done
}
trap cleanup EXIT

[ "$#" -eq 2 ] || { usage; exit 2; }

require_cmd vips
require_cmd vipsheader

FILE="$1"
OUT_FILE="$2"

[ -f "$FILE" ] || die "File input non trovato: $FILE"

TILE_SIZE="${TILE_SIZE:-512}"
JPEG_Q="${JPEG_Q:-90}"
PYRAMID_MIN_SIDE="${PYRAMID_MIN_SIDE:-4096}"
PYRAMID_MIN_LEVELS="${PYRAMID_MIN_LEVELS:-4}"
PYRAMID_MODE="${PYRAMID_MODE:-auto}"
PNG_COMPRESSION="${PNG_COMPRESSION:-deflate}"
ALPHA_POLICY="${ALPHA_POLICY:-deflate}"
FLATTEN_BACKGROUND="${FLATTEN_BACKGROUND:-255 255 255}"
CONVERT_CMYK_TO_SRGB="${CONVERT_CMYK_TO_SRGB:-1}"

case "$PYRAMID_MODE" in auto|force|none) ;; *) die "PYRAMID_MODE deve essere: auto, force o none" ;; esac
case "$PNG_COMPRESSION" in deflate|jpeg) ;; *) die "PNG_COMPRESSION deve essere: deflate o jpeg" ;; esac
case "$ALPHA_POLICY" in deflate|flatten) ;; *) die "ALPHA_POLICY deve essere: deflate o flatten" ;; esac
case "$CONVERT_CMYK_TO_SRGB" in 0|1) ;; *) die "CONVERT_CMYK_TO_SRGB deve essere: 0 o 1" ;; esac

EXT="$(lower "${FILE##*.}")"
OUT_EXT="$(lower "${OUT_FILE##*.}")"

case "$EXT" in
  jpg|jpeg|png|tif|tiff) ;;
  *) die "Formato input non supportato: .$EXT" ;;
esac

case "$OUT_EXT" in
  tif|tiff) ;;
  *) die "L'output deve avere estensione .tif o .tiff: $OUT_FILE" ;;
esac

WIDTH="$(vipsheader -f width "$FILE")"
HEIGHT="$(vipsheader -f height "$FILE")"
BANDS="$(vipsheader -f bands "$FILE")"
FORMAT="$(vipsheader -f format "$FILE" 2>/dev/null || echo unknown)"
INTERPRETATION="$(vipsheader -f interpretation "$FILE" 2>/dev/null || echo unknown)"

[[ "$WIDTH" =~ ^[0-9]+$ ]] || die "Impossibile leggere width da: $FILE"
[[ "$HEIGHT" =~ ^[0-9]+$ ]] || die "Impossibile leggere height da: $FILE"
[[ "$BANDS" =~ ^[0-9]+$ ]] || die "Impossibile leggere bands da: $FILE"

MAX=$(( WIDTH > HEIGHT ? WIDTH : HEIGHT ))
TMP_SIDE="$MAX"
LEVELS=1

while [ "$TMP_SIDE" -gt "$TILE_SIZE" ]; do
  TMP_SIDE=$(( (TMP_SIDE + 1) / 2 ))
  LEVELS=$(( LEVELS + 1 ))
done

PYRAMID_ARGS=()
case "$PYRAMID_MODE" in
  force)
    PYRAMID_ARGS=(--pyramid --subifd)
    ;;
  none)
    PYRAMID_ARGS=()
    ;;
  auto)
    if [ "$MAX" -gt "$PYRAMID_MIN_SIDE" ] && [ "$LEVELS" -ge "$PYRAMID_MIN_LEVELS" ]; then
      PYRAMID_ARGS=(--pyramid --subifd)
    fi
    ;;
esac

HAS_ALPHA=0
if [ "$EXT" = "png" ]; then
  if [ "$BANDS" -eq 2 ] || [ "$BANDS" -eq 4 ]; then
    HAS_ALPHA=1
  fi
elif [ "$EXT" = "tif" ] || [ "$EXT" = "tiff" ]; then
  if [ "$BANDS" -eq 2 ]; then
    HAS_ALPHA=1
  elif [ "$BANDS" -eq 4 ] && [ "$INTERPRETATION" != "cmyk" ]; then
    HAS_ALPHA=1
  elif [ "$BANDS" -eq 5 ] && [ "$INTERPRETATION" = "cmyk" ]; then
    HAS_ALPHA=1
  fi
fi

SOURCE_FOR_SAVE="$FILE"

FORCE_DEFLATE=0
if [ "$HAS_ALPHA" -eq 1 ]; then
  if [ "$ALPHA_POLICY" = "flatten" ]; then
    TMP_FLAT="$(make_temp_v)"
    cleanup_files+=("$TMP_FLAT")
    log "Alpha rilevato: flatten su background [$FLATTEN_BACKGROUND]"
    vips flatten "$SOURCE_FOR_SAVE" "$TMP_FLAT" --background "$FLATTEN_BACKGROUND"
    SOURCE_FOR_SAVE="$TMP_FLAT"
    HAS_ALPHA=0
  else
    FORCE_DEFLATE=1
  fi
fi

if [ "$INTERPRETATION" = "cmyk" ] && [ "$CONVERT_CMYK_TO_SRGB" = "1" ]; then
  TMP_SRGB="$(make_temp_v)"
  cleanup_files+=("$TMP_SRGB")
  log "CMYK rilevato: conversione derivato a sRGB"
  vips colourspace "$SOURCE_FOR_SAVE" "$TMP_SRGB" srgb
  SOURCE_FOR_SAVE="$TMP_SRGB"
  INTERPRETATION="srgb"
fi

COMPRESSION_ARGS=()
if [ "$FORMAT" != "uchar" ]; then
  COMPRESSION_ARGS=(--compression deflate)
  COMPRESSION_REASON="formato pixel $FORMAT: uso deflate"
elif [ "$FORCE_DEFLATE" -eq 1 ]; then
  COMPRESSION_ARGS=(--compression deflate)
  COMPRESSION_REASON="alpha preservato: uso deflate"
elif [ "$EXT" = "png" ] && [ "$PNG_COMPRESSION" = "deflate" ]; then
  COMPRESSION_ARGS=(--compression deflate)
  COMPRESSION_REASON="PNG: uso deflate come default conservativo"
else
  COMPRESSION_ARGS=(--compression jpeg --Q "$JPEG_Q")
  COMPRESSION_REASON="uso JPEG Q$JPEG_Q"
fi

mkdir -p "$(dirname "$OUT_FILE")"

log "Input: $FILE"
log "Output: $OUT_FILE"
log "Dimensione: ${WIDTH}x${HEIGHT}, max=$MAX"
log "Tile: ${TILE_SIZE}x${TILE_SIZE}"
if [ "${#PYRAMID_ARGS[@]}" -gt 0 ]; then
  log "Piramide: SI (${PYRAMID_MODE})"
else
  log "Piramide: NO (${PYRAMID_MODE})"
fi
log "Compressione: $COMPRESSION_REASON"

vips tiffsave "$SOURCE_FOR_SAVE" "$OUT_FILE" \
  "${COMPRESSION_ARGS[@]}" \
  --tile \
  --tile-width "$TILE_SIZE" \
  --tile-height "$TILE_SIZE" \
  "${PYRAMID_ARGS[@]}"

log "Completato"
exit 0
