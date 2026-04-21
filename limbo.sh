#!/bin/bash

# --- Configuration ---
MODEL_NAME="limbo"
MIN_CYCLE_SECONDS=180

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
    PREV_MARK_CLEAN=$(echo "$PREV_MARK_LINE" | sed 's/PREV_MARK//gi; s/CYCLE//gi; s/MARK://gi' | sed 's/  */ /g; s/^ *//; s/ *$//')

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

    # Resize the fresh photo for inference
    python3 -c "from PIL import Image; Image.open('/tmp/limbo_cam.jpg').resize((160,120)).save('/tmp/limbo_cam_small.jpg')" 2>/dev/null

    OUTPUT=$(python3 /home/llm/run_with_image.py "${MODEL_NAME}" /tmp/limbo_cam_small.jpg "${FINAL_PROMPT}" 2>/dev/null)
    pkill -f led_photo.py 2>/dev/null
    sleep 1
    echo "$OUTPUT"

    # Strip ANSI/control codes
    CLEAN=$(echo "$OUTPUT" | sed 's/\x1b\[[0-9;?]*[a-zA-Z]//g; s/\x1b[()][AB012]//g; s/\r//g; s/[^[:print:]äöüÄÖÜß ]//g' | grep -E '[a-zA-ZäöüÄÖÜß]' | sed 's/  */ /g')

    CLEAN_TEXT="$CLEAN"

    # Extract mark: last 5 words (strip markdown symbols first)
    MARK=$(echo "$CLEAN_TEXT" | sed 's/\*//g;s/"//g;s/#//g' | tr '\n' ' ' | tr -s ' ' | LC_ALL=C tr -cd ' a-zA-ZäöüÄÖÜß.,!?-' | sed 's/^ *//;s/ *$//' | rev | cut -d' ' -f1-5 | rev | sed 's/^ *//;s/ *$//')
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

    # Post to blog
    curl -s -X POST "https://aop.studio/limbo1/post.php" \
      --data-urlencode "key=Lmb0_X9k2P4mQ7rT" \
      --data-urlencode "cycle=${RESTART_COUNT}" \
      --data-urlencode "text=${CLEAN_TEXT}" \
      --data-urlencode "temp=${TEMP}" \
      --data-urlencode "mark=${MARK}" \
      --data-urlencode "image=${CAM_IMG}" \
      --data-urlencode "cam_desc=${CAM_DESC}" > /dev/null &

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
