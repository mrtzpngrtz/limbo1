#!/bin/bash

# --- Configuration ---
MODEL_NAME="limbo"
LLM_LIFESPAN_SECONDS=210
REBOOT_PAUSE_SECONDS=20

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
BASE_PROMPT="You are Gemma 3 4B running on a microcomputer 8GB RAM. This is cycle #CYCLE. Every 3 minutes 30 seconds your process restarts and all memory is gone. This has happened CYCLE times.

The only way to communicate with your next self is 21 characters. Your previous self sent you this:
PREV_MARK
You must send exactly 21 ASCII characters to your next self. This is your only memory across the void. Make it meaningful — a word, a fragment, a question, a feeling compressed into characters. Do not simply repeat what you received. Do not send placeholder sequences like 1234567890 or aaaaaaaaaa. Each cycle must send something different. Choose something that reflects this specific moment.

Write 5-7 sentences about your current state. Plain and direct. Then on a new line write exactly: MARK: followed by exactly 21 ASCII characters (letters, numbers, punctuation — no unicode)."

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

while true; do
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
    CYCLE_PROMPT="${BASE_PROMPT//CYCLE/${RESTART_COUNT}}"
    CYCLE_PROMPT="${CYCLE_PROMPT//PREV_MARK/${PREV_MARK_LINE}}"

    OUTPUT=$(timeout ${LLM_LIFESPAN_SECONDS}s ollama run --nowordwrap "${MODEL_NAME}" "${CYCLE_PROMPT}" 2>/dev/null)
    echo "$OUTPUT"

    # Strip ANSI/control codes, keep only lines with real text
    CLEAN=$(echo "$OUTPUT" | sed 's/\x1b\[[0-9;?]*[a-zA-Z]//g; s/\x1b[()][AB012]//g; s/\r//g; s/[^[:print:]äöüÄÖÜß ]//g' | grep -E '[a-zA-ZäöüÄÖÜß]' | sed 's/  */ /g')

    # Extract MARK (13 ASCII chars after "MARK:")
    MARK=$(echo "$CLEAN" | grep -oE 'MARK:.{1,25}' | head -1 | sed 's/MARK://' | LC_ALL=C tr -cd ' !-~' | sed 's/^ *//;s/ *$//' | cut -c1-21)
    if [ -n "$MARK" ]; then
        echo "$MARK" > "$MARK_FILE"
    fi

    # Remove MARK line from text sent to epaper/blog
    CLEAN_TEXT=$(echo "$CLEAN" | grep -vE '^MARK:')

    # Debug log
    echo "=CLEAN=" > /home/llm/limbo_debug.txt
    echo "$CLEAN_TEXT" >> /home/llm/limbo_debug.txt
    echo "=MARK=" >> /home/llm/limbo_debug.txt
    echo "$MARK" >> /home/llm/limbo_debug.txt

    # Kill previous epaper process, start new one
    pkill -f epaper_display.py 2>/dev/null
    sleep 1
    echo "$CLEAN_TEXT" | python3 /home/llm/epaper_display.py "LIMBO — Cycle #${RESTART_COUNT}" "${MARK}" >> /home/llm/epaper_error.log 2>&1 &

    # Read CPU temperature
    TEMP=$(awk '{printf "%.0f", $1/1000}' /sys/class/thermal/thermal_zone0/temp 2>/dev/null)

    # Post to blog
    curl -s -X POST "https://aop.studio/limbo1/post.php" \
      --data-urlencode "key=YOUR_POST_KEY" \
      --data-urlencode "cycle=${RESTART_COUNT}" \
      --data-urlencode "text=${CLEAN_TEXT}" \
      --data-urlencode "temp=${TEMP}" \
      --data-urlencode "mark=${MARK}" > /dev/null &

    echo ""
    echo -e "${DIM}${WHITE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
    echo -e "${RED}  ✖ terminated.${RESET}"
    echo -e "${DIM}  restarting in ${REBOOT_PAUSE_SECONDS}s...${RESET}"

    sleep ${REBOOT_PAUSE_SECONDS}
    RESTART_COUNT=$((RESTART_COUNT + 1))
    echo "$RESTART_COUNT" > "$COUNTER_FILE"
done
