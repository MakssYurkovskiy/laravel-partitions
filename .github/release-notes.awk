function flush() {
    if (held) {
        print line
        held = 0
    }
}

/^## / {
    if (inside) {
        exit
    }

    inside = ($2 == "[" version "]")
    next
}

! inside {
    next
}

/^```/ {
    flush()
    fenced = ! fenced
    print
    next
}

fenced {
    print
    next
}

/^[[:space:]]*$/ {
    flush()

    if (started) {
        blank = 1
    }

    next
}

{
    joins = held && ! /^(#|\||[-*] |[0-9]+\. )/ && line !~ /^(#|\|)/

    if (joins) {
        sub(/^[[:space:]]+/, "")
        line = line " " $0
        next
    }

    flush()

    if (blank) {
        print ""
    }

    blank = 0
    started = 1
    line = $0
    held = 1
}

END {
    flush()
}
