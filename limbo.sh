#!/bin/bash

# --- Configuration ---
MODEL_NAME="limbo"
MIN_CYCLE_SECONDS=180
POST_URL="https://aop.studio/limbo1/post.php"
POST_KEY="Lmb0_X9k2P4mQ7rT"
QUEUE_DIR="/home/llm/post_queue"

# --- Blog post queue ---
# Every post is written to disk first and sent from there, so a network outage
# (or a dead blog) loses nothing: whatever is still waiting is retried each cycle,
# oldest first. Posts the server rejects (4xx) are parked in $QUEUE_DIR/failed.
enqueue_post() {   # cycle text temp mark image cam_desc
    local d="$QUEUE_DIR/$(printf '%06d' "$1")" tmp
    tmp="$QUEUE_DIR/.tmp-$(printf '%06d' "$1")"
    mkdir -p "$tmp"
    printf '%s' "$1" > "$tmp/cycle"
    printf '%s' "$2" > "$tmp/text"
    printf '%s' "$3" > "$tmp/temp"
    printf '%s' "$4" > "$tmp/mark"
    printf '%s' "$5" > "$tmp/image"
    printf '%s' "$6" > "$tmp/cam_desc"
    rm -rf "$d"
    mv "$tmp" "$d"
}

flush_posts() {
    local d code waiting
    for d in "$QUEUE_DIR"/[0-9]*; do
        [ -d "$d" ] || continue
        code=$(curl -s -m 60 -o /dev/null -w '%{http_code}' "$POST_URL" \
            --data-urlencode "key=${POST_KEY}" \
            --data-urlencode "cycle@$d/cycle" \
            --data-urlencode "text@$d/text" \
            --data-urlencode "temp@$d/temp" \
            --data-urlencode "mark@$d/mark" \
            --data-urlencode "image@$d/image" \
            --data-urlencode "cam_desc@$d/cam_desc")
        case "$code" in
            200)     rm -rf "$d" ;;
            400|403) mkdir -p "$QUEUE_DIR/failed"; rm -rf "$QUEUE_DIR/failed/$(basename "$d")"; mv "$d" "$QUEUE_DIR/failed/"
                     echo "  blog rejected cycle $(basename "$d") (HTTP $code), parked in failed/" ;;
            *)   waiting=$(ls -d "$QUEUE_DIR"/[0-9]* 2>/dev/null | wc -l)
                 echo "  blog unreachable (HTTP $code), $waiting post(s) waiting"
                 return 1 ;;
        esac
    done
}

flush_posts_bg() {   # never two flushes at once, never block the cycle
    ( flock -n 9 || exit 0; flush_posts ) 9>"$QUEUE_DIR/.lock" &
}
mkdir -p "$QUEUE_DIR"
# --- end post queue ---

# --- ANSI Colors ---
RESET="\033[0m"
BOLD="\033[1m"
DIM="\033[2m"
RED="\033[31m"
YELLOW="\033[33m"
CYAN="\033[36m"
WHITE="\033[37m"

# --- Set large font on this terminal ---
setfont /usr/share/consolefonts/Lat15-Terminus32x16.psf.gz 2>/dev/null

# --- Prompt ---
PROMPT_FILE="/home/llm/prompt.txt"
BASE_PROMPT=$(cat "$PROMPT_FILE")

# --- Files ---
COUNTER_FILE="/home/llm/.limbo_restarts"
MARK_FILE="/home/llm/.limbo_mark"
clear
echo -e "${BOLD}${CYAN}"
echo "  ██╗     ██╗███╗   ███╗██████╗  ██████╗ "
echo "  ██║     ██║████╗ ████║██╔══██╗██╔═══██╗"
echo "  ██║     ██║██╔████╔██║██████╔╝██║   ██║"
echo "  ██║     ██║██║╚██╔╝██║██╔══██╗██║   ██║"
echo "  ███████╗██║██║ ╚═╝ ██║██████╔╝╚██████╔╝"
echo "  ╚══════╝╚═╝╚═╝     ╚═╝╚═════╝  ╚═════╝ "
echo -e "${RESET}"
echo -e "${DIM}${WHITE}  limbo LLM${RESET}"
echo ""
sleep 2

if [ -f "$COUNTER_FILE" ]; then
    RESTART_COUNT=$(cat "$COUNTER_FILE")
else
    RESTART_COUNT=0
fi

# Anything that could not be posted before the restart goes out now
flush_posts_bg

# Capture startup image so first cycle has real camera data
echo -e "${DIM}  capturing startup image...${RESET}"
libcamera-still -o /tmp/limbo_cam.jpg --nopreview -t 400 --gain 8 --width 320 --height 240 2>/dev/null || \
    fswebcam -r 320x240 --no-banner /tmp/limbo_cam.jpg 2>/dev/null
python3 /home/llm/cam_describe.py /tmp/limbo_cam.jpg > /tmp/limbo_cam_desc.txt 2>/dev/null
python3 /home/llm/cam_dither.py   /tmp/limbo_cam.jpg > /tmp/limbo_cam_b64.txt  2>/dev/null

while true; do
    CYCLE_START=$(date +%s)
    clear
    echo -e "${DIM}${WHITE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
    echo -e "${BOLD}${YELLOW}  CYCLE #${RESTART_COUNT}${RESET}"
    echo -e "${DIM}${WHITE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
    echo ""

    # Inject previous mark
    if [ -f "$MARK_FILE" ]; then
        PREV_MARK_LINE="$(cat $MARK_FILE)"
    else
        PREV_MARK_LINE="(none — this is the first cycle)"
    fi
    PREV_MARK_CLEAN=$(printf '%s\n' "$PREV_MARK_LINE" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')

    # Read camera image data from previous cycle
    CAM_IMG=$(cat /tmp/limbo_cam_b64.txt 2>/dev/null || echo "")
    CAM_DESC=$(cat /tmp/limbo_cam_desc.txt 2>/dev/null || echo "")

    CYCLE_PROMPT="${BASE_PROMPT//CYCLE/${RESTART_COUNT}}"
    FINAL_PROMPT="${CYCLE_PROMPT//PREV_MARK/${PREV_MARK_CLEAN}}"

    # led_photo.py: live camera 30s countdown → takes photo → shows still + blinking INFERENCE
    pkill -f led_display.py 2>/dev/null
    pkill -f led_photo.py 2>/dev/null
    pkill -f led_loading.py 2>/dev/null
    sleep 1
    rm -f /tmp/limbo_photo_ready
    python3 /home/llm/led_photo.py "#${RESTART_COUNT}" &

    # Wait for led_photo.py to finish the countdown and take the photo
    until [ -f /tmp/limbo_photo_ready ]; do sleep 1; done
    PHOTO_AGE=$(( $(date +%s) - $(stat -c %Y /tmp/limbo_cam.jpg 2>/dev/null || echo 0) ))
    echo -e "${DIM}  photo taken ${PHOTO_AGE}s ago${RESET}"

    # Generate dithered blog image from this cycle's fresh photo
    python3 /home/llm/cam_dither.py /tmp/limbo_cam.jpg > /tmp/limbo_cam_b64.txt 2>/dev/null
    CAM_IMG=$(cat /tmp/limbo_cam_b64.txt 2>/dev/null || echo "")

    # Image for inference: upright (the camera is mounted rotated, same as the blog image),
    # scaled from the 1296x972 capture to 672x912, which is exactly the grid Gemma 4 uses
    # internally (42x57 patches, 266 tokens), so nothing is upscaled and nothing costs extra.
    rm -f /tmp/limbo_cam_small.jpg
    python3 -c "from PIL import Image; Image.open('/tmp/limbo_cam.jpg').rotate(-90, expand=True).resize((672, 912), Image.LANCZOS).save('/tmp/limbo_cam_small.jpg', quality=90)" 2>/dev/null

    OUTPUT=$(python3 /home/llm/run_with_image.py "${MODEL_NAME}" /tmp/limbo_cam_small.jpg "${FINAL_PROMPT}" 2>/dev/null)
    pkill -f led_photo.py 2>/dev/null
    sleep 1
    echo "$OUTPUT"

    # Strip ANSI/control codes
    CLEAN=$(echo "$OUTPUT" | sed 's/\x1b\[[0-9;?]*[a-zA-Z]//g; s/\x1b[()][AB012]//g; s/\r//g; s/[^[:print:]äöüÄÖÜß ]//g' | grep -E '[a-zA-ZäöüÄÖÜß]' | sed 's/  */ /g')

    CLEAN_TEXT="$CLEAN"

    # Extract the mark: the five words the model wrote after "MARK:" on its last line.
    # The answer follows the thinking, and the thinking often quotes "MARK:" while
    # reasoning about the instructions, so the LAST occurrence is the real one.
    # Fallback if the model wrote no MARK line: its last five words.
    MARK=$(printf '%s\n' "$CLEAN_TEXT" | sed 's/[*_`#"]//g' | perl -e '
        local $/; my $t = <STDIN>; my $m = "";
        if ($t =~ /^.*(?:\A|\n)[ \t]*MARK[ \t]*:[ \t]*([^\n]*)/s) { $m = $1; }
        $m =~ s/^(?:MARK[ \t]*:[ \t]*)+//;
        my @w = grep { /[[:alnum:]]/ } map { s/^[^[:alnum:]]+|[^[:alnum:]]+$//gr } split /\s+/, $m;
        if (!@w) { @w = grep { /[[:alnum:]]/ } map { s/^[^[:alnum:]]+|[^[:alnum:]]+$//gr } split /\s+/, $t; @w = @w[-5..-1] if @w > 5; }
        @w = @w[0..4] if @w > 5;
        print join(" ", @w);
    ')
    # The MARK line itself is not part of the text shown on the LED / posted to the blog
    CLEAN_TEXT=$(printf '%s\n' "$CLEAN_TEXT" | grep -vE '^[[:space:]*_#]*MARK[[:space:]]*:')
    if [ -n "$MARK" ]; then
        echo "$MARK" > "$MARK_FILE"
    fi

    # Debug log
    echo "=RAW=" > /home/llm/limbo_debug.txt
    echo "$OUTPUT" >> /home/llm/limbo_debug.txt
    echo "=CLEAN=" >> /home/llm/limbo_debug.txt
    echo "$CLEAN_TEXT" >> /home/llm/limbo_debug.txt
    echo "=MARK=" >> /home/llm/limbo_debug.txt
    echo "$MARK" >> /home/llm/limbo_debug.txt

    # Show text on LED (BLOCKING — hold 5s → scroll → wait 2s → exit)
    echo "$CLEAN_TEXT" | python3 /home/llm/led_display.py "LIMBO — Cycle #${RESTART_COUNT}" "${MARK}"

    # Read CPU temperature
    TEMP=$(awk '{printf "%.0f", $1/1000}' /sys/class/thermal/thermal_zone0/temp 2>/dev/null)

    # Post to blog: written to the queue first, then sent together with anything
    # still waiting from earlier cycles (see flush_posts)
    enqueue_post "$RESTART_COUNT" "$CLEAN_TEXT" "$TEMP" "$MARK" "$CAM_IMG" "$CAM_DESC"
    flush_posts_bg

    echo ""
    echo -e "${DIM}${WHITE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
    echo -e "${RED}  ✖ terminated.${RESET}"

    RESTART_COUNT=$((RESTART_COUNT + 1))
    echo "$RESTART_COUNT" > "$COUNTER_FILE"

    # Run YOLO on the photo taken this cycle (ready for next cycle's prompt)
    python3 /home/llm/cam_describe.py /tmp/limbo_cam.jpg > /tmp/limbo_cam_desc.txt 2>/dev/null &

    # Wait until MIN_CYCLE_SECONDS have elapsed since cycle start
    ELAPSED=$(( $(date +%s) - CYCLE_START ))
    REMAINING=$(( MIN_CYCLE_SECONDS - ELAPSED ))
    if [ "$REMAINING" -gt 0 ]; then
        echo -e "${DIM}  waiting ${REMAINING}s...${RESET}"
        pkill -f led_display.py 2>/dev/null; sleep 1
        echo "$CLEAN_TEXT" | python3 /home/llm/led_display.py "LIMBO — Cycle #$((RESTART_COUNT - 1))" --loop &
        sleep "$REMAINING"
        pkill -f led_display.py 2>/dev/null
        sleep 1
    fi
done
